<?php
/**
 * Админка: управление заведениями (А2).
 *
 * Список всех заведений (включая скрытые), форма добавления нового
 * и форма редактирования для каждой строки. Жёсткого удаления нет —
 * заведение скрывают через is_active, а не убирают из БД.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/venues.php';

requireRole(ROLE_ADMIN);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
    } else {
        $action = $_POST['action'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');

        switch ($action) {
            case 'add':
                if ($name === '' || $address === '') {
                    $error = 'Название и адрес обязательны.';
                } else {
                    createVenue($name, $address);
                    header('Location: /admin/venues.php');
                    exit;
                }
                break;

            case 'update':
                $id = (int) ($_POST['id'] ?? 0);
                $isActive = isset($_POST['is_active']);

                if ($id <= 0 || $name === '' || $address === '') {
                    $error = 'Название и адрес обязательны.';
                } else {
                    updateVenue($id, $name, $address, $isActive);
                    header('Location: /admin/venues.php');
                    exit;
                }
                break;

            default:
                $error = 'Неизвестное действие.';
        }
    }
}

$venues = listVenues();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Заведения — админка UniFood</title>
</head>
<body>
<h1>Заведения</h1>

<?php if ($error !== ''): ?>
    <p><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<ul>
    <?php foreach ($venues as $venue): ?>
        <li>
            <form method="post" action="/admin/venues.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int) $venue['id'] ?>">
                <input type="text" name="name" value="<?= htmlspecialchars($venue['name']) ?>" required>
                <input type="text" name="address" value="<?= htmlspecialchars($venue['address']) ?>" required>
                <label>
                    <input type="checkbox" name="is_active" value="1" <?= $venue['is_active'] ? 'checked' : '' ?>>
                    Активно
                </label>
                <button type="submit">Сохранить</button>
            </form>
        </li>
    <?php endforeach; ?>
</ul>

<h2>Добавить заведение</h2>
<form method="post" action="/admin/venues.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
    <input type="hidden" name="action" value="add">
    <p>
        <label>Название: <input type="text" name="name" required></label>
    </p>
    <p>
        <label>Адрес: <input type="text" name="address" required></label>
    </p>
    <button type="submit">Добавить</button>
</form>

<p><a href="/admin/categories.php">Категории</a> · <a href="/">На главную</a></p>
</body>
</html>
