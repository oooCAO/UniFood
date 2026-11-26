<?php
/**
 * Страница ошибки.
 *
 * Простая заготовка для случаев, когда нужно показать пользователю
 * сообщение без сложной логики. Текст можно передать через $errorMessage.
 * Страницу можно открыть и напрямую, поэтому сессию стартуем до вывода HTML.
 */

require_once __DIR__ . '/../src/auth.php';

startSession();

// Ошибка на странице — это ответ 500, а не успешный 200.
http_response_code(500);

$errorMessage = $errorMessage ?? 'Что-то пошло не так. Попробуйте обновить страницу.';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ошибка — UniFood</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="container">
    <section class="card empty-state empty-state--error">
        <p class="empty-code">500</p>
        <h1 class="page-title">Ошибка</h1>
        <p class="error"><?= htmlspecialchars($errorMessage) ?></p>
        <p><a class="btn btn-primary" href="/">На главную</a></p>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
