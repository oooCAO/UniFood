<?php
/**
 * Временные слоты получения заказа.
 *
 * Жёсткого удаления нет: на слот ссылается orders.slot_id. Вместо
 * удаления админ скрывает слот через is_active.
 */

require_once __DIR__ . '/db_mysql.php';

/**
 * Возвращает слоты одного заведения.
 *
 * Включает прошедшие и неактивные слоты — админке нужно видеть всё,
 * студенту прошедшие и неактивные отдаёт отдельный запрос в src/order.php.
 *
 * @param int $venueId Id заведения.
 * @return array Список строк pickup_slots, отсортированный по дате и времени.
 */
function listSlots(int $venueId): array
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT id, venue_id, slot_date, start_time, end_time, capacity, is_active
         FROM pickup_slots
         WHERE venue_id = ?
         ORDER BY slot_date, start_time'
    );
    $stmt->execute([$venueId]);

    return $stmt->fetchAll();
}

/**
 * Добавляет новый слот.
 *
 * @param int $venueId Id заведения.
 * @param string $date Дата слота (YYYY-MM-DD).
 * @param string $startTime Время начала (HH:MM или HH:MM:SS).
 * @param string $endTime Время конца.
 * @param int $capacity Максимум заказов на слот.
 * @return int Id созданного слота.
 */
function createSlot(int $venueId, string $date, string $startTime, string $endTime, int $capacity): int
{
    $stmt = getMysqlConnection()->prepare(
        'INSERT INTO pickup_slots (venue_id, slot_date, start_time, end_time, capacity) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$venueId, $date, $startTime, $endTime, $capacity]);

    return (int) getMysqlConnection()->lastInsertId();
}

/**
 * Обновляет слот: время, вместимость и активность.
 *
 * @param int $id Id слота.
 * @param string $date Новая дата.
 * @param string $startTime Новое время начала.
 * @param string $endTime Новое время конца.
 * @param int $capacity Новая вместимость.
 * @param bool $isActive Доступен ли слот для выбора студентом.
 * @return bool true, если слот с таким id найден и обновлён.
 */
function updateSlot(int $id, string $date, string $startTime, string $endTime, int $capacity, bool $isActive): bool
{
    $stmt = getMysqlConnection()->prepare(
        'UPDATE pickup_slots SET slot_date = ?, start_time = ?, end_time = ?, capacity = ?, is_active = ?
         WHERE id = ?'
    );
    $stmt->execute([$date, $startTime, $endTime, $capacity, $isActive ? 1 : 0, $id]);

    return $stmt->rowCount() > 0;
}
