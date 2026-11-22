<?php
/**
 * Статистика для администратора (А7).
 *
 * Считаем на лету по orders/order_items в MySQL и по order_status_history
 * в MongoDB — отдельной "предрасчитанной" коллекции для статистики нет:
 * держать её в синхроне было бы лишней сложностью без необходимости.
 *
 * Все функции принимают одинаковый набор фильтров: заведение (null — все)
 * и диапазон дат по orders.created_at (null — без ограничения).
 */

require_once __DIR__ . '/db_mysql.php';
require_once __DIR__ . '/db_mongo.php';

/**
 * Строит условие WHERE и параметры для фильтра по заведению и датам.
 *
 * Общая часть для getOrderStats() и getTopDishes(), чтобы не повторять
 * одну и ту же сборку условий дважды.
 *
 * @param int|null $venueId Id заведения или null для всех.
 * @param string|null $dateFrom Дата начала периода (YYYY-MM-DD) или null.
 * @param string|null $dateTo Дата конца периода (YYYY-MM-DD) или null.
 * @return array{sql: string, params: array} Условие (с ведущим "AND ...") и параметры.
 */
function buildStatsFilter(?int $venueId, ?string $dateFrom, ?string $dateTo): array
{
    $sql = '';
    $params = [];

    if ($venueId !== null) {
        $sql .= ' AND orders.venue_id = ?';
        $params[] = $venueId;
    }

    if ($dateFrom !== null && $dateFrom !== '') {
        $sql .= ' AND orders.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== null && $dateTo !== '') {
        $sql .= ' AND orders.created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Возвращает количество выданных заказов и выручку по ним.
 *
 * Считаем только status = completed: отменённые и незавершённые заказы
 * не выручка, а оформленные, но не выданные, могут ещё измениться.
 *
 * @param int|null $venueId Id заведения или null для всех.
 * @param string|null $dateFrom Дата начала периода.
 * @param string|null $dateTo Дата конца периода.
 * @return array{orders_count: int, revenue: float} Сводка.
 */
function getOrderStats(?int $venueId, ?string $dateFrom, ?string $dateTo): array
{
    $filter = buildStatsFilter($venueId, $dateFrom, $dateTo);

    $stmt = getMysqlConnection()->prepare(
        "SELECT COUNT(*) AS orders_count, COALESCE(SUM(total_price), 0) AS revenue
         FROM orders
         WHERE status = 'completed'" . $filter['sql']
    );
    $stmt->execute($filter['params']);

    $row = $stmt->fetch();

    return [
        'orders_count' => (int) $row['orders_count'],
        'revenue' => (float) $row['revenue'],
    ];
}

/**
 * Возвращает самые популярные блюда по количеству проданных порций.
 *
 * Считаем в тех же рамках, что и выручку: только выданные заказы.
 *
 * @param int|null $venueId Id заведения или null для всех.
 * @param string|null $dateFrom Дата начала периода.
 * @param string|null $dateTo Дата конца периода.
 * @param int $limit Сколько блюд вернуть.
 * @return array Список [name, total_quantity], по убыванию количества.
 */
function getTopDishes(?int $venueId, ?string $dateFrom, ?string $dateTo, int $limit = 5): array
{
    $filter = buildStatsFilter($venueId, $dateFrom, $dateTo);

    $stmt = getMysqlConnection()->prepare(
        "SELECT dishes.name, SUM(order_items.quantity) AS total_quantity
         FROM order_items
         JOIN orders ON orders.id = order_items.order_id
         JOIN dishes ON dishes.id = order_items.dish_id
         WHERE orders.status = 'completed'" . $filter['sql'] . "
         GROUP BY dishes.id, dishes.name
         ORDER BY total_quantity DESC
         LIMIT " . (int) $limit
    );
    $stmt->execute($filter['params']);

    return $stmt->fetchAll();
}

/**
 * Возвращает среднее время от оформления заказа до выдачи, в минутах.
 *
 * MySQL хранит только текущий статус, а не время каждого перехода,
 * поэтому момент выдачи берём из MongoDB: запись order_status_history
 * с new_status = completed. У заказов без такой записи (например, если
 * запись в Mongo не удалась при смене статуса) время не считаем.
 *
 * @param int|null $venueId Id заведения или null для всех.
 * @param string|null $dateFrom Дата начала периода.
 * @param string|null $dateTo Дата конца периода.
 * @return float|null Среднее время в минутах или null, если нечего считать.
 */
function getAverageFulfillmentMinutes(?int $venueId, ?string $dateFrom, ?string $dateTo): ?float
{
    $filter = buildStatsFilter($venueId, $dateFrom, $dateTo);

    $stmt = getMysqlConnection()->prepare(
        "SELECT id, created_at FROM orders WHERE status = 'completed'" . $filter['sql']
    );
    $stmt->execute($filter['params']);

    $orders = $stmt->fetchAll();

    if (empty($orders)) {
        return null;
    }

    $orderIds = array_map(static fn (array $order): int => (int) $order['id'], $orders);

    $historyDocs = getMongoDatabase()->selectCollection('order_status_history')->find([
        'order_id' => ['$in' => $orderIds],
        'new_status' => 'completed',
    ]);

    // Переход в completed разрешён только один раз на заказ (см. AGENTS.md),
    // поэтому на каждый id достаточно одной записи.
    $completedAt = [];

    foreach ($historyDocs as $doc) {
        $completedAt[(int) $doc['order_id']] = $doc['changed_at']->toDateTime();
    }

    $totalMinutes = 0.0;
    $matched = 0;

    foreach ($orders as $order) {
        $orderId = (int) $order['id'];

        if (!isset($completedAt[$orderId])) {
            continue;
        }

        $createdAt = new DateTime($order['created_at']);
        $minutes = ($completedAt[$orderId]->getTimestamp() - $createdAt->getTimestamp()) / 60;

        $totalMinutes += $minutes;
        $matched++;
    }

    return $matched > 0 ? $totalMinutes / $matched : null;
}
