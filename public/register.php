<?php
/**
 * Страница регистрации студента.
 *
 * Показывает форму, проверяет введённые данные и создаёт пользователя
 * в таблице users. После успешной регистрации сразу выполняет вход
 * и отправляет на главную страницу.
 */

require_once __DIR__ . '/../src/auth.php';

// Уже вошедшему пользователю регистрация не нужна.
if (isLoggedIn()) {
    header('Location: /');
    exit;
}

$errors = [];
$email = '';
$fullName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Форма устарела. Обновите страницу и попробуйте снова.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Введите корректный email.';
    }

    if ($fullName === '' || mb_strlen($fullName) > 100) {
        $errors[] = 'Введите имя (не больше 100 символов).';
    }

    // Минимальную длину проверяем здесь, чтобы не пропускать совсем
    // короткие пароли: password_hash() сам длину не ограничивает.
    if (mb_strlen($password) < 6) {
        $errors[] = 'Пароль должен быть не короче 6 символов.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'Пароли не совпадают.';
    }

    // Проверяем email отдельно, чтобы не ловить ошибку уникального ключа
    // из базы и показать пользователю понятный текст.
    if (empty($errors) && findUserByEmail($email) !== null) {
        $errors[] = 'Пользователь с таким email уже зарегистрирован.';
    }

    if (empty($errors)) {
        $userId = registerUser($email, $fullName, $password);

        loginUser(['id' => $userId, 'role' => ROLE_STUDENT]);

        header('Location: /');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Регистрация — UniFood</title>
</head>
<body>
<h1>Регистрация</h1>

<?php foreach ($errors as $error): ?>
    <p><?= htmlspecialchars($error) ?></p>
<?php endforeach; ?>

<form method="post" action="/register.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">

    <p>
        <label for="email">Email</label><br>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
    </p>

    <p>
        <label for="full_name">Имя</label><br>
        <input type="text" id="full_name" name="full_name" maxlength="100" value="<?= htmlspecialchars($fullName) ?>" required>
    </p>

    <p>
        <label for="password">Пароль</label><br>
        <input type="password" id="password" name="password" required>
    </p>

    <p>
        <label for="password_confirm">Повторите пароль</label><br>
        <input type="password" id="password_confirm" name="password_confirm" required>
    </p>

    <button type="submit">Зарегистрироваться</button>
</form>

<p>Уже есть аккаунт? <a href="/login.php">Войти</a></p>
</body>
</html>
