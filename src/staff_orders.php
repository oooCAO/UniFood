<?php
/**
 * Заказы для сотрудника заведения.
 *
 * Сотрудник обслуживает только своё заведение (users.venue_id), поэтому
 * каждый запрос здесь дополнительно фильтруется по venue_id — так же,
 * как заказы студента в order_history.php фильтруются по user_id.
 */

require_once __DIR__ . '/db_mysql.php';
require_once __DIR__ . '/db_mongo.php';

/**
 * Разрешённые переходы статуса заказа.
 *
 * Ключ — текущий статус, значение — список статусов, в которые из него
 * можно перейти. ready и completed в cancelled не уходят: после готовности
 * или выдачи отменять поздно (см. AGENTS.md).
 *
 * @return array Карта переходов [текущий статус => [следующие статусы]].
 */
function getStatusTransitions(): array
{
    return [
        'new' => ['confirmed', 'cancelled'],
        'confirmed' => ['ready', 'cancelled'],
        'ready' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];
}

/**
 * Проверяет, разрешён ли переход между статусами.
 *
 * @param string $from Текущий статус.
 * @param string $to Новый статус.
 * @return bool true, если переход есть в карте getStatusTransitions().
 */
function isValidStatusTransition(string $from, string $to): bool
{
    $transitions = getStatusTransitions();

    return in_array($to, $transitions[$from] ?? [], true);
}

/**
 * Возвращает id заведения, в котором работает сотрудник.
 *
 * venue_id не хранится в сессии (её пишет auth.php), поэтому берём
 * его из таблицы users по id текущего пользователя.
 *
 * @param int $userId id пользователя с ролью staff.
 * @return int|null id заведения или null, если сотрудник ни к одному не привязан.
 */
function getStaffVenueId(int $userId): ?int
{
    $stmt = getMysqlConnection()->prepare('SELECT venue_id FROM users WHERE id = ?');
    $stmt->execute([$userId]);

    $venueId = $stmt->fetchColumn();

    return $venueId === null || $venueId === false ? null : (int) $venueId;
}

/**
 * Возвращает заказы заведения сотрудника.
 *
 * @param int $venueId id заведения сотрудника.
 * @param string|null $statusFilter Если задан, вернёт только заказы с этим статусом.
 * @return array Список заказов: id, status, total_price, created_at,
 *               student_name, slot_date, start_time, end_time.
 */
function listOrdersForStaff(int $venueId, ?string $statusFilter = null): array
{
    $sql = 'SELECT orders.id, orders.status, orders.total_price, orders.created_at,
                   users.full_name AS student_name,
                   pickup_slots.slot_date, pickup_slots.start_time, pickup_slots.end_time
            FROM orders
            JOIN users ON users.id = orders.user_id
            JOIN pickup_slots ON pickup_slots.id = orders.slot_id
            WHERE orders.venue_id = ?';
    $params = [$venueId];

    if ($statusFilter !== null) {
        $sql .= ' AND orders.status = ?';
        $params[] = $statusFilter;
    }

    $sql .= ' ORDER BY orders.created_at ASC, orders.id ASC';

    $stmt = getMysqlConnection()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Возвращает один заказ заведения сотрудника вместе с составом.
 *
 * Условие orders.venue_id = ? обязательно: сотрудник не должен увидеть
 * заказ чужого заведения, даже если подставит чужой id в адрес страницы.
 *
 * @param int $venueId id заведения сотрудника.
 * @param int $orderId id заказа из адреса страницы.
 * @return array|null Заказ с полем items или null, если заказа нет или он чужого заведения.
 */
function getOrderForStaff(int $venueId, int $orderId): ?array
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT orders.id, orders.status, orders.total_price, orders.created_at,
                users.full_name AS student_name, users.email AS student_email,
                pickup_slots.slot_date, pickup_slots.start_time, pickup_slots.end_time
         FROM orders
         JOIN users ON users.id = orders.user_id
         JOIN pickup_slots ON pickup_slots.id = orders.slot_id
         WHERE orders.venue_id = ? AND orders.id = ?'
    );
    $stmt->execute([$venueId, $orderId]);

    $order = $stmt->fetch();

    if ($order === false) {
        return null;
    }

    $itemsStmt = getMysqlConnection()->prepare(
        'SELECT dishes.name, order_items.quantity, order_items.price
         FROM order_items
         JOIN dishes ON dishes.id = order_items.dish_id
         WHERE order_items.order_id = ?
         ORDER BY order_items.id'
    );
    $itemsStmt->execute([$orderId]);

    $order['items'] = $itemsStmt->fetchAll();

    return $order;
}

/**
 * Меняет статус заказа и пишет запись в историю MongoDB.
 *
 * Сначала обновляется orders.status (источник правды), затем пишется
 * документ в order_status_history. Если Mongo недоступна, статус
 * в MySQL не откатывается — операция считается выполненной с предупреждением.
 *
 * @param int $venueId id заведения сотрудника (проверка, что заказ его).
 * @param int $orderId id заказа.
 * @param string $newStatus Новый статус.
 * @param int $staffUserId id сотрудника, который меняет статус.
 * @return array{ok: bool, error: string|null, historyWarning: bool} Результат операции.
 */
function changeOrderStatus(int $venueId, int $orderId, string $newStatus, int $staffUserId): array
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT status FROM orders WHERE id = ? AND venue_id = ?'
    );
    $stmt->execute([$orderId, $venueId]);
    $currentStatus = $stmt->fetchColumn();

    if ($currentStatus === false) {
        return ['ok' => false, 'error' => 'Заказ не найден.', 'historyWarning' => false];
    }

    if (!isValidStatusTransition($currentStatus, $newStatus)) {
        return [
            'ok' => false,
            'error' => 'Такой переход статуса не разрешён.',
            'historyWarning' => false,
        ];
    }

    $updateStmt = getMysqlConnection()->prepare(
        'UPDATE orders SET status = ? WHERE id = ? AND venue_id = ?'
    );
    $updateStmt->execute([$newStatus, $orderId, $venueId]);

    $historyWarning = false;

    try {
        getMongoDatabase()->selectCollection('order_status_history')->insertOne([
            'order_id' => $orderId,
            'old_status' => $currentStatus,
            'new_status' => $newStatus,
            'changed_by' => $staffUserId,
            'changed_at' => new MongoDB\BSON\UTCDateTime(),
        ]);
    } catch (Throwable $e) {
        error_log('Не удалось записать историю статуса заказа в MongoDB: ' . $e->getMessage());
        $historyWarning = true;
    }

    return ['ok' => true, 'error' => null, 'historyWarning' => $historyWarning];
}
