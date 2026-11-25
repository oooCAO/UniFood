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
    $sql = 'SELECT id, name, address, is_active, menu_image_code, menu_text, created_at FROM venues';

    if ($onlyActive) {
        $sql .= ' WHERE is_active = 1';
    }

    $sql .= ' ORDER BY name';

    return getMysqlConnection()->query($sql)->fetchAll();
}

/**
 * Выбирает заведение по умолчанию для страниц админки с выбором заведения.
 *
 * Предпочитает первое активное: иначе страница по умолчанию может
 * открыться на скрытом заведении и выглядеть пустой (например, если
 * оно отсортировано по названию раньше остальных).
 *
 * @param array $venues Список заведений, как возвращает listVenues().
 * @return int Id заведения по умолчанию или 0, если список пуст.
 */
function getDefaultVenueId(array $venues): int
{
    foreach ($venues as $venue) {
        if ($venue['is_active']) {
            return (int) $venue['id'];
        }
    }

    return (int) ($venues[0]['id'] ?? 0);
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
        'SELECT id, name, address, is_active, menu_image_code, menu_text, created_at FROM venues WHERE id = ?'
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
 * Обновляет заведение: название, адрес, видимость и меню (картинка + текст).
 *
 * @param int $id Id заведения.
 * @param string $name Новое название.
 * @param string $address Новый адрес.
 * @param bool $isActive Показывать ли заведение студентам.
 * @param string|null $menuImageCode Код заведения для картинки меню
 *   (например 'oz'), пустая строка сохраняется как null.
 * @param string|null $menuText Текст меню, пустая строка сохраняется как null.
 * @return bool true, если строка с таким id найдена и обновлена.
 */
function updateVenue(
    int $id,
    string $name,
    string $address,
    bool $isActive,
    ?string $menuImageCode,
    ?string $menuText
): bool {
    $menuImageCode = $menuImageCode !== null && trim($menuImageCode) !== '' ? trim($menuImageCode) : null;
    $menuText = $menuText !== null && trim($menuText) !== '' ? trim($menuText) : null;

    $stmt = getMysqlConnection()->prepare(
        'UPDATE venues SET name = ?, address = ?, is_active = ?, menu_image_code = ?, menu_text = ? WHERE id = ?'
    );
    $stmt->execute([trim($name), trim($address), $isActive ? 1 : 0, $menuImageCode, $menuText, $id]);

    return $stmt->rowCount() > 0;
}

/**
 * Собирает ссылку на картинку дневного меню заведения с market.grsu.by.
 *
 * Картинка на сайте перезаливается каждый день под тем же именем с текущей
 * датой, поэтому ссылка не хранится в БД целиком, а собирается на лету.
 *
 * @param string|null $menuImageCode Код заведения (например 'oz'), как в venues.menu_image_code.
 * @return string|null Ссылка на картинку или null, если код не задан.
 */
function buildMenuImageUrl(?string $menuImageCode): ?string
{
    if ($menuImageCode === null || $menuImageCode === '') {
        return null;
    }

    return 'https://market.grsu.by/image/catalog/menustolovaya/' . $menuImageCode . date('d.m.Y') . '.jpg';
}
