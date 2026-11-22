<?php
/**
 * Админка: управление блюдами (А4, А5).
 *
 * Как и categories.php, показывает блюда одного заведения за раз
 * (выбор сверху через GET venue_id). Без категории в заведении
 * добавить блюдо нельзя — форма добавления не показывается.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/venues.php';
require_once __DIR__ . '/../../src/categories.php';
require_once __DIR__ . '/../../src/dishes.php';

requireRole(ROLE_ADMIN);

$error = '';
$venues = listVenues();

$venueId = (int) ($_GET['venue_id'] ?? ($venues[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
    } else {
        $action = $_POST['action'] ?? '';
        $venueId = (int) ($_POST['venue_id'] ?? $venueId);

        switch ($action) {
            case 'add':
                $categoryId = (int) ($_POST['category_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $price = (float) ($_POST['price'] ?? 0);

                if ($name === '' || $categoryId <= 0 || $price <= 0) {
                    $error = 'Название, категория и цена обязательны, цена больше нуля.';
                } elseif (createDish($categoryId, $name, $description, $price) === null) {
                    $error = 'Выбранной категории не существует.';
                } else {
                    header('Location: /admin/dishes.php?venue_id=' . $venueId);
                    exit;
                }
                break;

            case 'update':
                $id = (int) ($_POST['id'] ?? 0);
                $categoryId = (int) ($_POST['category_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $price = (float) ($_POST['price'] ?? 0);
                $isAvailable = isset($_POST['is_available']);

                if ($id <= 0 || $name === '' || $categoryId <= 0 || $price <= 0) {
                    $error = 'Название, категория и цена обязательны, цена больше нуля.';
                } elseif (!updateDish($id, $categoryId, $name, $description, $price, $isAvailable)) {
                    $error = 'Выбранной категории не существует.';
                } else {
                    header('Location: /admin/dishes.php?venue_id=' . $venueId);
                    exit;
                }
                break;

            default:
                $error = 'Неизвестное действие.';
        }
    }
}

$categories = $venueId > 0 ? listCategories($venueId) : [];
$dishes = $venueId > 0 ? listDishes($venueId) : [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Блюда — админка UniFood</title>
</head>
<body>
<h1>Блюда</h1>

<?php if ($error !== ''): ?>
    <p><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if (empty($venues)): ?>
    <p>Сначала нужно добавить заведение.</p>
<?php else: ?>
    <form method="get" action="/admin/dishes.php">
        <label>
            Заведение:
            <select name="venue_id" onchange="this.form.submit()">
                <?php foreach ($venues as $venue): ?>
                    <option value="<?= (int) $venue['id'] ?>" <?= $venue['id'] == $venueId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($venue['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <?php if (empty($categories)): ?>
        <p>В этом заведении пока нет категорий. <a href="/admin/categories.php?venue_id=<?= (int) $venueId ?>">Добавить категорию</a>, прежде чем добавлять блюда.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($dishes as $dish): ?>
                <li>
                    <form method="post" action="/admin/dishes.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int) $dish['id'] ?>">
                        <input type="hidden" name="venue_id" value="<?= (int) $venueId ?>">
                        <input type="text" name="name" value="<?= htmlspecialchars($dish['name']) ?>" required>
                        <input type="text" name="description" value="<?= htmlspecialchars($dish['description']) ?>">
                        <select name="category_id">
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>" <?= $category['id'] == $dish['category_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="price" value="<?= htmlspecialchars((string) $dish['price']) ?>" step="0.01" min="0.01" required>
                        <label>
                            <input type="checkbox" name="is_available" value="1" <?= $dish['is_available'] ? 'checked' : '' ?>>
                            Доступно
                        </label>
                        <button type="submit">Сохранить</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>

        <h2>Добавить блюдо</h2>
        <form method="post" action="/admin/dishes.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="venue_id" value="<?= (int) $venueId ?>">
            <p>
                <label>Категория:
                    <select name="category_id">
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>
            <p>
                <label>Название: <input type="text" name="name" required></label>
            </p>
            <p>
                <label>Описание: <input type="text" name="description"></label>
            </p>
            <p>
                <label>Цена: <input type="number" name="price" step="0.01" min="0.01" required></label>
            </p>
            <button type="submit">Добавить</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<p><a href="/admin/venues.php">Заведения</a> · <a href="/admin/categories.php">Категории</a> · <a href="/">На главную</a></p>
</body>
</html>
