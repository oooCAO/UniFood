<?php
/**
 * Заведения (столовые и кафе).
 *
 * Жёсткого удаления нет: заведение, на которое уже есть ссылки (категории,
 * блюда, заказы), нельзя убрать из БД без нарушения внешних ключей.
 * Вместо удаления админ скрывает заведение через is_active.
 */

require_once __DIR__ . '/db_mysql.php';

/**
 * Возвращает список заведений.
 *
 * @param bool $onlyActive Если true, вернёт только активные заведения
 *   (для страниц студента). Админке нужны все, поэтому по умолчанию false.
 * @return array Список строк venues, отсортированный по названию.
 */
function listVenues(bool $onlyActive = false): array
{
    $sql = 'SELECT id, name, address, is_active, created_at FROM venues';

    if ($onlyActive) {
        $sql .= ' WHERE is_active = 1';
    }

    $sql .= ' ORDER BY name';

    return getMysqlConnection()->query($sql)->fetchAll();
}

/**
 * Возвращает одно заведение по id.
 *
 * @param int $id Id заведения.
 * @return array|null Строка venues или null, если такого id нет.
 */
function getVenueById(int $id): ?array
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT id, name, address, is_active, created_at FROM venues WHERE id = ?'
    );
    $stmt->execute([$id]);

    $venue = $stmt->fetch();

    return $venue === false ? null : $venue;
}

/**
 * Добавляет новое заведение.
 *
 * @param string $name Название.
 * @param string $address Адрес.
 * @return int Id созданного заведения.
 */
function createVenue(string $name, string $address): int
{
    $stmt = getMysqlConnection()->prepare(
        'INSERT INTO venues (name, address) VALUES (?, ?)'
    );
    $stmt->execute([trim($name), trim($address)]);

    return (int) getMysqlConnection()->lastInsertId();
}

/**
 * Обновляет заведение: название, адрес и видимость для студентов.
 *
 * @param int $id Id заведения.
 * @param string $name Новое название.
 * @param string $address Новый адрес.
 * @param bool $isActive Показывать ли заведение студентам.
 * @return bool true, если строка с таким id найдена и обновлена.
 */
function updateVenue(int $id, string $name, string $address, bool $isActive): bool
{
    $stmt = getMysqlConnection()->prepare(
        'UPDATE venues SET name = ?, address = ?, is_active = ? WHERE id = ?'
    );
    $stmt->execute([trim($name), trim($address), $isActive ? 1 : 0, $id]);

    return $stmt->rowCount() > 0;
}
