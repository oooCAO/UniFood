<?php
/**
 * Категории меню.
 *
 * Каждая категория принадлежит одному заведению (venue_id). Удалить
 * категорию можно только если на неё не ссылается ни одно блюдо —
 * иначе блюда останутся без категории.
 */

require_once __DIR__ . '/db_mysql.php';

/**
 * Возвращает категории одного заведения.
 *
 * @param int $venueId Id заведения.
 * @return array Список строк categories, отсортированный по sort_order.
 */
function listCategories(int $venueId): array
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT id, venue_id, name, sort_order FROM categories WHERE venue_id = ? ORDER BY sort_order, name'
    );
    $stmt->execute([$venueId]);

    return $stmt->fetchAll();
}

/**
 * Возвращает одну категорию по id.
 *
 * @param int $id Id категории.
 * @return array|null Строка categories или null, если такого id нет.
 */
function getCategoryById(int $id): ?array
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT id, venue_id, name, sort_order FROM categories WHERE id = ?'
    );
    $stmt->execute([$id]);

    $category = $stmt->fetch();

    return $category === false ? null : $category;
}

/**
 * Добавляет новую категорию в заведение.
 *
 * Проверяет, что заведение существует, до INSERT: без этой проверки
 * несуществующий venue_id (например, подставленный прямым POST) давал
 * необработанный PDOException с утечкой пути и SQL в ответе страницы.
 * Также не даёт создать вторую категорию с тем же названием в том же
 * заведении — иначе в каталоге появляются двойники.
 *
 * @param int $venueId Id заведения, которому принадлежит категория.
 * @param string $name Название категории.
 * @param int $sortOrder Порядок показа в меню.
 * @return int|null Id созданной категории или null, если заведения нет
 *   или в нём уже есть категория с таким названием.
 */
function createCategory(int $venueId, string $name, int $sortOrder): ?int
{
    $pdo = getMysqlConnection();

    $stmt = $pdo->prepare('SELECT id FROM venues WHERE id = ?');
    $stmt->execute([$venueId]);

    if ($stmt->fetch() === false) {
        return null;
    }

    $name = trim($name);

    $stmt = $pdo->prepare('SELECT id FROM categories WHERE venue_id = ? AND name = ?');
    $stmt->execute([$venueId, $name]);

    if ($stmt->fetch() !== false) {
        return null;
    }

    $insert = $pdo->prepare(
        'INSERT INTO categories (venue_id, name, sort_order) VALUES (?, ?, ?)'
    );
    $insert->execute([$venueId, $name, $sortOrder]);

    return (int) $pdo->lastInsertId();
}

/**
 * Обновляет название и порядок категории.
 *
 * Заведение категории не меняем: если блюдо нужно перенести в другое
 * заведение, для этого есть управление блюдами, а не категориями.
 *
 * @param int $id Id категории.
 * @param string $name Новое название.
 * @param int $sortOrder Новый порядок показа.
 * @return bool true, если строка с таким id найдена и обновлена.
 */
function updateCategory(int $id, string $name, int $sortOrder): bool
{
    $stmt = getMysqlConnection()->prepare(
        'UPDATE categories SET name = ?, sort_order = ? WHERE id = ?'
    );
    $stmt->execute([trim($name), $sortOrder, $id]);

    return $stmt->rowCount() > 0;
}

/**
 * Считает, сколько блюд ссылается на категорию.
 *
 * Нужно перед удалением: если блюда есть, удалять категорию нельзя.
 *
 * @param int $categoryId Id категории.
 * @return int Количество блюд в категории.
 */
function countDishesInCategory(int $categoryId): int
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT COUNT(*) FROM dishes WHERE category_id = ?'
    );
    $stmt->execute([$categoryId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Удаляет категорию, если на неё не ссылается ни одно блюдо.
 *
 * @param int $id Id категории.
 * @return bool true, если категория удалена; false, если в ней есть блюда
 *   или строка с таким id не найдена.
 */
function deleteCategory(int $id): bool
{
    if (countDishesInCategory($id) > 0) {
        return false;
    }

    $stmt = getMysqlConnection()->prepare('DELETE FROM categories WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->rowCount() > 0;
}
