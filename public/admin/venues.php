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
        $menuImageCode = trim($_POST['menu_image_code'] ?? '');
        $menuText = trim($_POST['menu_text'] ?? '');

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
                } elseif ($menuImageCode !== '' && !preg_match('/^[a-zA-Z0-9]{1,10}$/', $menuImageCode)) {
                    $error = 'Код картинки меню: только латинские буквы и цифры, до 10 символов.';
                } elseif (mb_strlen($menuText) > 5000) {
                    $error = 'Текст меню слишком длинный (максимум 5000 символов).';
                } else {
                    updateVenue($id, $name, $address, $isActive, $menuImageCode, $menuText);
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
$pageTitle = 'Заведения — админка UniFood';
include __DIR__ . '/admin-header.php';
?>
<h1 class="admin-page-title">Заведения</h1>

<?php if ($error !== ''): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<ul class="admin-list">
    <?php foreach ($venues as $venue): ?>
        <li>
            <form method="post" action="/admin/venues.php" class="admin-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int) $venue['id'] ?>">
                <input type="text" name="name" value="<?= htmlspecialchars($venue['name']) ?>" required>
                <input type="text" name="address" value="<?= htmlspecialchars($venue['address']) ?>" required>
                <label>
                    Код картинки меню (например «oz»):
                    <input type="text" name="menu_image_code" value="<?= htmlspecialchars($venue['menu_image_code'] ?? '') ?>" maxlength="10" placeholder="oz">
                </label>
                <label>
                    Текст меню:
                    <textarea name="menu_text" rows="6" placeholder="Показывается на странице меню заведения"><?= htmlspecialchars($venue['menu_text'] ?? '') ?></textarea>
                </label>
                <label>
                    <input type="checkbox" name="is_active" value="1" <?= $venue['is_active'] ? 'checked' : '' ?>>
                    Активно
                </label>
                <button type="submit">Сохранить</button>
            </form>
        </li>
    <?php endforeach; ?>
</ul>

<h2 class="admin-section-title">Добавить заведение</h2>
<form method="post" action="/admin/venues.php" class="admin-form">
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

<?php include __DIR__ . '/admin-footer.php'; ?>
