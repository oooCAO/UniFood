<?php
/**
 * Админка: управление пользователями (А1).
 *
 * Список всех пользователей, форма создания (с любой ролью — обычная
 * регистрация даёт только студента) и смена роли/заведения для
 * существующих. Удаления нет: на users.id ссылаются заказы.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/venues.php';
require_once __DIR__ . '/../../src/users.php';

requireRole(ROLE_ADMIN);

$error = '';
$venues = listVenues();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
    } else {
        $action = $_POST['action'] ?? '';
        $role = $_POST['role'] ?? '';
        $venueId = (int) ($_POST['venue_id'] ?? 0);
        $venueId = $venueId > 0 ? $venueId : null;

        if (!in_array($role, [ROLE_STUDENT, ROLE_STAFF, ROLE_ADMIN], true)) {
            $error = 'Неизвестная роль.';
        } elseif ($role === ROLE_STAFF && $venueId === null) {
            $error = 'Для роли «сотрудник» нужно выбрать заведение.';
        } else {
            switch ($action) {
                case 'add':
                    $email = trim($_POST['email'] ?? '');
                    $fullName = trim($_POST['full_name'] ?? '');
                    $password = (string) ($_POST['password'] ?? '');

                    if ($email === '' || $fullName === '' || $password === '') {
                        $error = 'Email, имя и пароль обязательны.';
                    } elseif (createUser($email, $fullName, $password, $role, $venueId) === null) {
                        $error = 'Пользователь с таким email уже существует.';
                    } else {
                        header('Location: /admin/users.php');
                        exit;
                    }
                    break;

                case 'update':
                    $id = (int) ($_POST['id'] ?? 0);

                    if ($id <= 0) {
                        $error = 'Пользователь не найден.';
                    } else {
                        updateUserRole($id, $role, $venueId);
                        header('Location: /admin/users.php');
                        exit;
                    }
                    break;

                default:
                    $error = 'Неизвестное действие.';
            }
        }
    }
}

$users = listUsers();
$roleLabels = [
    ROLE_STUDENT => 'Студент',
    ROLE_STAFF => 'Сотрудник',
    ROLE_ADMIN => 'Администратор',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Пользователи — админка UniFood</title>
</head>
<body>
<h1>Пользователи</h1>

<?php if ($error !== ''): ?>
    <p><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<ul>
    <?php foreach ($users as $user): ?>
        <li>
            <?= htmlspecialchars($user['email']) ?> — <?= htmlspecialchars($user['full_name']) ?>
            <form method="post" action="/admin/users.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                <select name="role">
                    <?php foreach ($roleLabels as $code => $label): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= $user['role'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="venue_id">
                    <option value="0">— без заведения —</option>
                    <?php foreach ($venues as $venue): ?>
                        <option value="<?= (int) $venue['id'] ?>" <?= $user['venue_id'] == $venue['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($venue['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Сохранить</button>
            </form>
        </li>
    <?php endforeach; ?>
</ul>

<h2>Создать пользователя</h2>
<form method="post" action="/admin/users.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
    <input type="hidden" name="action" value="add">
    <p>
        <label>Email: <input type="email" name="email" required></label>
    </p>
    <p>
        <label>Имя: <input type="text" name="full_name" required></label>
    </p>
    <p>
        <label>Пароль: <input type="password" name="password" required></label>
    </p>
    <p>
        <label>Роль:
            <select name="role">
                <?php foreach ($roleLabels as $code => $label): ?>
                    <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </p>
    <p>
        <label>Заведение (только для сотрудника):
            <select name="venue_id">
                <option value="0">— без заведения —</option>
                <?php foreach ($venues as $venue): ?>
                    <option value="<?= (int) $venue['id'] ?>"><?= htmlspecialchars($venue['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </p>
    <button type="submit">Создать</button>
</form>

<p><a href="/admin/venues.php">Заведения</a> · <a href="/admin/categories.php">Категории</a> · <a href="/admin/dishes.php">Блюда</a> · <a href="/">На главную</a></p>
</body>
</html>
