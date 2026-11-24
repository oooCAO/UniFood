<?php
/**
 * История заказов студента.
 *
 * Здесь только чтение: список своих заказов, подробности одного заказа и
 * история смены его статусов. Страница orders.php показывает то, что вернут
 * эти функции. Текущий статус берём из таблицы orders (MySQL), а журнал
 * переходов — из MongoDB order_status_history, куда пишет сотрудник.
 *
 * Заказы всегда ищем вместе с user_id текущего пользователя: так чужой
 * заказ нельзя открыть, даже если угадать его id.
 */

require_once __DIR__ . '/db_mysql.php';
require_once __DIR__ . '/db_mongo.php';

/**
 * Возвращает русские подписи статусов заказа.
 *
 * Ключи совпадают со значениями ENUM в таблице orders.
 *
 * @return array Массив [код статуса => подпись].
 */
function getOrderStatusLabels(): array
{
    return [
        'new' => 'Новый',
        'confirmed' => 'Подтверждён',
        'ready' => 'Готов к выдаче',
        'completed' => 'Получен',
        'cancelled' => 'Отменён',
    ];
}

/**
 * Возвращает подпись статуса для показа на странице.
 *
 * Если в базе появится новый статус, а подписи для него ещё нет,
 * показываем сам код: страница не должна падать из-за этого.
 *
 * @param string $status Код статуса из orders.status.
 * @return string Подпись на русском.
 */
function getOrderStatusLabel(string $status): string
{
    $labels = getOrderStatusLabels();

    return $labels[$status] ?? $status;
}

/**
 * Возвращает все заказы пользователя с заведением и слотом получения.
 *
 * Новые заказы идут первыми. Состав заказа эта функция не достаёт:
 * его подгружает getUserOrdersWithItems(), чтобы не делать запрос
 * на каждый заказ.
 *
 * @param int $userId id текущего пользователя.
 * @return array Список заказов: id, status, total_price, created_at,
 *               venue_name, address, slot_date, start_time, end_time.
 */
function getUserOrders(int $userId): array
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT orders.id, orders.status, orders.total_price, orders.created_at,
                venues.name AS venue_name, venues.address,
                pickup_slots.slot_date, pickup_slots.start_time, pickup_slots.end_time
         FROM orders
         JOIN venues ON venues.id = orders.venue_id
         JOIN pickup_slots ON pickup_slots.id = orders.slot_id
         WHERE orders.user_id = ?
         ORDER BY orders.created_at DESC, orders.id DESC'
    );
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

/**
 * Возвращает один заказ пользователя.
 *
 * Условие orders.user_id = ? обязательно: чужой заказ должен выглядеть
 * так же, как несуществующий.
 *
 * @param int $userId id текущего пользователя.
 * @param int $orderId id заказа из адреса страницы.
 * @return array|null Строка заказа или null, если заказа нет или он чужой.
 */
function getUserOrder(int $userId, int $orderId): ?array
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT orders.id, orders.status, orders.total_price, orders.created_at,
                venues.name AS venue_name, venues.address,
                pickup_slots.slot_date, pickup_slots.start_time, pickup_slots.end_time
         FROM orders
         JOIN venues ON venues.id = orders.venue_id
         JOIN pickup_slots ON pickup_slots.id = orders.slot_id
         WHERE orders.user_id = ? AND orders.id = ?'
    );
    $stmt->execute([$userId, $orderId]);

    $order = $stmt->fetch();

    return $order === false ? null : $order;
}

/**
 * Возвращает состав сразу нескольких заказов.
 *
 * Один запрос на весь список вместо запроса на каждый заказ.
 * Цена берётся из order_items.price: это снимок цены на момент заказа,
 * текущая цена в dishes для старых заказов не подходит.
 *
 * @param array $orderIds Список id заказов.
 * @return array Массив [id заказа => список позиций]. Позиция: name,
 *               quantity, price.
 */
function getOrderItemsForOrders(array $orderIds): array
{
    if (empty($orderIds)) {
        return [];
    }

    // Подготовленные выражения не принимают массив в IN (),
    // поэтому ставим столько ?, сколько заказов в списке.
    $orderIds = array_map('intval', $orderIds);
    $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));

    $stmt = getMysqlConnection()->prepare(
        'SELECT order_items.order_id, order_items.quantity, order_items.price,
                dishes.name
         FROM order_items
         JOIN dishes ON dishes.id = order_items.dish_id
         WHERE order_items.order_id IN (' . $placeholders . ')
         ORDER BY order_items.id'
    );
    $stmt->execute($orderIds);

    $items = [];

    foreach ($stmt->fetchAll() as $item) {
        $items[(int) $item['order_id']][] = $item;
    }

    return $items;
}

/**
 * Возвращает заказы пользователя вместе с составом.
 *
 * Собирает результат getUserOrders() и getOrderItemsForOrders():
 * у каждого заказа появляется поле items.
 *
 * @param int $userId id текущего пользователя.
 * @return array Список заказов с полем items.
 */
function getUserOrdersWithItems(int $userId): array
{
    $orders = getUserOrders($userId);

    if (empty($orders)) {
        return [];
    }

    $orderIds = [];

    foreach ($orders as $order) {
        $orderIds[] = (int) $order['id'];
    }

    $items = getOrderItemsForOrders($orderIds);

    foreach ($orders as $index => $order) {
        $orders[$index]['items'] = $items[(int) $order['id']] ?? [];
    }

    return $orders;
}

/**
 * Возвращает один заказ пользователя вместе с составом.
 *
 * @param int $userId id текущего пользователя.
 * @param int $orderId id заказа.
 * @return array|null Заказ с полем items или null, если заказа нет или он чужой.
 */
function getUserOrderWithItems(int $userId, int $orderId): ?array
{
    $order = getUserOrder($userId, $orderId);

    if ($order === null) {
        return null;
    }

    $items = getOrderItemsForOrders([(int) $order['id']]);
    $order['items'] = $items[(int) $order['id']] ?? [];

    return $order;
}

/**
 * Возвращает историю смены статусов заказа из MongoDB.
 *
 * Записи пишет сотрудник при смене статуса (src/staff_orders.php), здесь
 * только чтение. В документе хранится changed_by — id пользователя, поэтому
 * имена сотрудников подтягиваем из MySQL одним запросом на все записи.
 *
 * changed_at в Mongo хранится в UTC, поэтому переводим его в пояс Минска:
 * слоты получения заданы местным временем, и в UTC смена статуса выглядела
 * бы на три часа раньше, чем она была на самом деле.
 *
 * @param int $orderId id заказа. Что заказ свой, проверяет вызывающий код.
 * @return array Записи по возрастанию времени: old_status, new_status,
 *               changed_by, changed_by_name, changed_at (DateTime в поясе Минска).
 * @throws MongoDB\Driver\Exception\ConnectionException Если Mongo недоступна.
 */
function getOrderStatusHistory(int $orderId): array
{
    // _id — второй ключ сортировки: у двух изменений подряд changed_at может
    // совпасть до миллисекунды, и тогда порядок записей был бы случайным.
    $documents = getMongoDatabase()->selectCollection('order_status_history')->find(
        ['order_id' => $orderId],
        ['sort' => ['changed_at' => 1, '_id' => 1]]
    );

    $history = [];
    $staffIds = [];

    foreach ($documents as $document) {
        $changedBy = (int) $document['changed_by'];

        $history[] = [
            'old_status' => (string) $document['old_status'],
            'new_status' => (string) $document['new_status'],
            'changed_by' => $changedBy,
            'changed_at' => $document['changed_at']->toDateTime()->setTimezone(new DateTimeZone('Europe/Minsk')),
        ];

        // Ключи массива не дают дублей, если один сотрудник менял статус дважды.
        $staffIds[$changedBy] = true;
    }

    if (empty($history)) {
        return [];
    }

    $staffNames = getUserNamesByIds(array_keys($staffIds));

    foreach ($history as $index => $entry) {
        $history[$index]['changed_by_name'] = $staffNames[$entry['changed_by']]
            ?? 'Сотрудник №' . $entry['changed_by'];
    }

    return $history;
}

/**
 * Возвращает имена пользователей по их id.
 *
 * Один запрос на весь список вместо запроса на каждую запись истории.
 *
 * @param array $userIds Список id пользователей.
 * @return array Массив [id пользователя => имя].
 */
function getUserNamesByIds(array $userIds): array
{
    if (empty($userIds)) {
        return [];
    }

    // Подготовленные выражения не принимают массив в IN (),
    // поэтому ставим столько ?, сколько id в списке.
    $userIds = array_map('intval', $userIds);
    $placeholders = implode(', ', array_fill(0, count($userIds), '?'));

    $stmt = getMysqlConnection()->prepare(
        'SELECT id, full_name FROM users WHERE id IN (' . $placeholders . ')'
    );
    $stmt->execute($userIds);

    $names = [];

    foreach ($stmt->fetchAll() as $user) {
        $names[(int) $user['id']] = $user['full_name'];
    }

    return $names;
}
