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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — UniFood</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="container">
    <section class="card auth-card">
        <h1 class="page-title auth-title">Вход</h1>
        <p class="text-muted auth-subtitle">Войдите, чтобы оформлять заказы и смотреть их статус.</p>

        <?php if ($error !== ''): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="post" action="/login.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">

            <p>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
            </p>

            <p>
                <label for="password">Пароль</label>
                <input type="password" id="password" name="password" required>
            </p>

            <button class="btn btn-primary auth-submit" type="submit">Войти</button>
        </form>

        <p class="auth-switch">Нет аккаунта? <a href="/register.php">Зарегистрироваться</a></p>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
