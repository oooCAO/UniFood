<?php
/**
 * Страница ошибки.
 *
 * Простая заготовка для случаев, когда нужно показать пользователю
 * сообщение без сложной логики. Текст можно передать через $errorMessage.
 */

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
    <h1 class="page-title">Ошибка</h1>
    <p class="error"><?= htmlspecialchars($errorMessage) ?></p>
    <p><a class="btn" href="/">На главную</a></p>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
