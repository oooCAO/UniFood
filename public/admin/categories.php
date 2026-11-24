<?php
/**
 * Админка: управление категориями (А3).
 *
 * Категории показываются по одному заведению за раз: сверху выбор
 * заведения (GET venue_id), снизу список его категорий, форма
 * добавления и удаление (запрещено, если в категории есть блюда).
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/venues.php';
require_once __DIR__ . '/../../src/categories.php';

requireRole(ROLE_ADMIN);

$error = '';
$venues = listVenues();

// Заведение по умолчанию — первое в списке, если в адресе его не указали.
$venueId = (int) ($_GET['venue_id'] ?? ($venues[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
    } else {
        $action = $_POST['action'] ?? '';
        $venueId = (int) ($_POST['venue_id'] ?? $venueId);

        switch ($action) {
            case 'add':
                $name = trim($_POST['name'] ?? '');
                $sortOrder = (int) ($_POST['sort_order'] ?? 0);

                if ($name === '' || $venueId <= 0) {
                    $error = 'Название обязательно.';
                } else {
                    createCategory($venueId, $name, $sortOrder);
                    header('Location: /admin/categories.php?venue_id=' . $venueId);
                    exit;
                }
                break;

            case 'update':
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $sortOrder = (int) ($_POST['sort_order'] ?? 0);

                if ($id <= 0 || $name === '') {
                    $error = 'Название обязательно.';
                } else {
                    updateCategory($id, $name, $sortOrder);
                    header('Location: /admin/categories.php?venue_id=' . $venueId);
                    exit;
                }
                break;

            case 'delete':
                $id = (int) ($_POST['id'] ?? 0);

                if (!deleteCategory($id)) {
                    $error = 'Нельзя удалить категорию: в ней есть блюда.';
                } else {
                    header('Location: /admin/categories.php?venue_id=' . $venueId);
                    exit;
                }
                break;

            default:
                $error = 'Неизвестное действие.';
        }
    }
}

$categories = $venueId > 0 ? listCategories($venueId) : [];
$pageTitle = 'Категории — админка UniFood';
include __DIR__ . '/admin-header.php';
?>
<h1 class="admin-page-title">Категории</h1>

<?php if ($error !== ''): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if (empty($venues)): ?>
    <p class="notice">Сначала нужно добавить заведение.</p>
<?php else: ?>
    <form method="get" action="/admin/categories.php" class="admin-filter-form">
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

    <ul class="admin-list">
        <?php foreach ($categories as $category): ?>
            <li>
                <form method="post" action="/admin/categories.php" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                    <input type="hidden" name="venue_id" value="<?= (int) $venueId ?>">
                    <input type="text" name="name" value="<?= htmlspecialchars($category['name']) ?>" required>
                    <input type="number" name="sort_order" value="<?= (int) $category['sort_order'] ?>">
                    <button type="submit">Сохранить</button>
                </form>
                <form method="post" action="/admin/categories.php" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                    <input type="hidden" name="venue_id" value="<?= (int) $venueId ?>">
                    <button type="submit">Удалить</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>

    <h2 class="admin-section-title">Добавить категорию</h2>
    <form method="post" action="/admin/categories.php" class="admin-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="venue_id" value="<?= (int) $venueId ?>">
        <p>
            <label>Название: <input type="text" name="name" required></label>
        </p>
        <p>
            <label>Порядок: <input type="number" name="sort_order" value="0"></label>
        </p>
        <button type="submit">Добавить</button>
    </form>
<?php endif; ?>

<?php include __DIR__ . '/admin-footer.php'; ?>
