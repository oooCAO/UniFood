<?php
/**
 * Страница с информацией о блюде.
 *
 * Ищет блюдо по id в данных из базы и показывает его описание.
 * Кнопка «В корзину» отправляет POST на /cart.php по тому же контракту,
 * что и страница меню.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/catalog-data.php';

// Иначе шапка вызовет startSession() после вывода HTML и cookie сессии не уйдёт.
startSession();

if ($catalogError !== '') {
    $errorMessage = $catalogError;
    include __DIR__ . '/error.php';
    exit;
}

$dishId = (int) ($_GET['id'] ?? 0);
$dish = null;
$categoryName = '';

foreach ($dishes as $item) {
    if ($item['id'] === $dishId) {
        $dish = $item;
        break;
    }
}

if ($dish !== null) {
    foreach ($categories as $category) {
        if ($category['id'] === $dish['category_id']) {
            $categoryName = $category['name'];
            break;
        }
    }
}

if ($dish === null) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

$pageTitle = 'Блюдо — ' . $dish['name'] . ' — UniFood';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="container">
    <p><a href="/menu.php">← Вернуться в меню</a></p>

    <h1 class="page-title"><?= htmlspecialchars($dish['name']) ?></h1>

    <article class="card dish-details">
        <p class="text-muted">Категория: <?= htmlspecialchars($categoryName) ?></p>
        <p><?= htmlspecialchars($dish['description']) ?></p>
        <p class="dish-price"><?= number_format($dish['price'], 2, ',', ' ') ?> ₽</p>

        <form method="post" action="/cart.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="dish_id" value="<?= (int) $dish['id'] ?>">
            <button class="btn btn-primary" type="submit">В корзину</button>
        </form>
    </article>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
