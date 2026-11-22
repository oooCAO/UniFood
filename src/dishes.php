<?php
/**
 * Блюда.
 *
 * Жёсткого удаления нет: на блюдо могут ссылаться order_items старых
 * заказов, удаление сломало бы их историю. Вместо удаления админ
 * скрывает блюдо через is_available.
 *
 * venue_id у блюда всегда берётся из его категории, а не из формы:
 * так dishes.venue_id не может разойтись с categories.venue_id.
 */

require_once __DIR__ . '/db_mysql.php';

/**
 * Возвращает блюда одного заведения вместе с названием категории.
 *
 * @param int $venueId Id заведения.
 * @return array Список строк dishes (включая недоступные) с полем category_name.
 */
function listDishes(int $venueId): array
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT dishes.id, dishes.category_id, dishes.name, dishes.description,
                dishes.price, dishes.is_available, categories.name AS category_name
         FROM dishes
         JOIN categories ON categories.id = dishes.category_id
         WHERE dishes.venue_id = ?
         ORDER BY categories.sort_order, dishes.name'
    );
    $stmt->execute([$venueId]);

    return $stmt->fetchAll();
}

/**
 * Возвращает одно блюдо по id.
 *
 * @param int $id Id блюда.
 * @return array|null Строка dishes или null, если такого id нет.
 */
function getDishById(int $id): ?array
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT id, venue_id, category_id, name, description, price, is_available FROM dishes WHERE id = ?'
    );
    $stmt->execute([$id]);

    $dish = $stmt->fetch();

    return $dish === false ? null : $dish;
}

/**
 * Возвращает id заведения, которому принадлежит категория.
 *
 * Используется, чтобы venue_id блюда всегда совпадал с venue_id
 * его категории, не принимая venue_id отдельно из формы.
 *
 * @param int $categoryId Id категории.
 * @return int|null venue_id категории или null, если категории нет.
 */
function getCategoryVenueId(int $categoryId): ?int
{
    $stmt = getMysqlConnection()->prepare('SELECT venue_id FROM categories WHERE id = ?');
    $stmt->execute([$categoryId]);

    $venueId = $stmt->fetchColumn();

    return $venueId === false ? null : (int) $venueId;
}

/**
 * Добавляет новое блюдо.
 *
 * @param int $categoryId Id категории (заведение берётся из неё).
 * @param string $name Название.
 * @param string $description Описание.
 * @param float $price Цена.
 * @return int|null Id созданного блюда или null, если категории не существует.
 */
function createDish(int $categoryId, string $name, string $description, float $price): ?int
{
    $venueId = getCategoryVenueId($categoryId);

    if ($venueId === null) {
        return null;
    }

    $stmt = getMysqlConnection()->prepare(
        'INSERT INTO dishes (venue_id, category_id, name, description, price) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$venueId, $categoryId, trim($name), trim($description), $price]);

    return (int) getMysqlConnection()->lastInsertId();
}

/**
 * Обновляет блюдо: категорию (и, соответственно, заведение), название,
 * описание, цену и доступность.
 *
 * @param int $id Id блюда.
 * @param int $categoryId Новая категория (заведение берётся из неё).
 * @param string $name Новое название.
 * @param string $description Новое описание.
 * @param float $price Новая цена.
 * @param bool $isAvailable Доступно ли блюдо для заказа.
 * @return bool true, если категория существует и блюдо с таким id обновлено.
 */
function updateDish(int $id, int $categoryId, string $name, string $description, float $price, bool $isAvailable): bool
{
    $venueId = getCategoryVenueId($categoryId);

    if ($venueId === null) {
        return false;
    }

    $stmt = getMysqlConnection()->prepare(
        'UPDATE dishes SET venue_id = ?, category_id = ?, name = ?, description = ?, price = ?, is_available = ?
         WHERE id = ?'
    );
    $stmt->execute([$venueId, $categoryId, trim($name), trim($description), $price, $isAvailable ? 1 : 0, $id]);

    return $stmt->rowCount() > 0;
}
