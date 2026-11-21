<?php
/**
 * Страница «не найдено».
 *
 * Показывается, когда запрошенная страница или блюдо не существует.
 */
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Страница не найдена — UniFood</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="container">
    <h1 class="page-title">Страница не найдена</h1>
    <p class="text-muted">Возможно, ссылка устарела или блюдо больше не доступно.</p>
    <p><a class="btn" href="/">На главную</a></p>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
