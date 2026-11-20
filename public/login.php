<?php
/**
 * Страница входа.
 *
 * Подходит всем ролям: студенту, сотруднику и администратору. Роль
 * не выбирается в форме, а берётся из таблицы users и записывается в сессию.
 */

require_once __DIR__ . '/../src/auth.php';

// Вошедшего пользователя отправляем на главную, чтобы он не вводил пароль снова.
if (isLoggedIn()) {
    header('Location: /');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
    } else {
        $user = findUserByEmail($email);

        // Одно сообщение на оба случая (нет email или неверный пароль):
        // так по ответу нельзя узнать, зарегистрирован ли человек.
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $error = 'Неверный email или пароль.';
        } else {
            loginUser($user);

            header('Location: /');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Вход — UniFood</title>
</head>
<body>
<h1>Вход</h1>

<?php if ($error !== ''): ?>
    <p><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post" action="/login.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">

    <p>
        <label for="email">Email</label><br>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
    </p>

    <p>
        <label for="password">Пароль</label><br>
        <input type="password" id="password" name="password" required>
    </p>

    <button type="submit">Войти</button>
</form>

<p>Нет аккаунта? <a href="/register.php">Зарегистрироваться</a></p>
</body>
</html>
