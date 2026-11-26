<?php
/**
 * Страница меню.
 *
 * Показывает блюда по категориям. Кнопка «В корзину» отправляет POST
 * на /cart.php с полями из контракта: csrf_token, action=add и dish_id.
 * Список берётся из базы через catalog-data.php.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/format.php';
require_once __DIR__ . '/catalog-data.php';

// Иначе шапка вызовет startSession() после вывода HTML и cookie сессии не уйдёт.
startSession();

$venueId = (int) ($_GET['venue'] ?? 0);

// Без параметра venue ссылка «Меню» больше не отдельный раздел — старые
// ссылки из закладок ведут на главную, а не на список всех столовых подряд.
if ($venueId <= 0) {
    header('Location: /');
    exit;
}

$venue = null;

foreach ($venues as $item) {
    if ($item['id'] === $venueId) {
        $venue = $item;
        break;
    }
}

// Неизвестное или отключённое заведение — это 404, а не всё меню подряд.
if ($venue === null) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

$pageTitle = $venue === null ? 'Меню — UniFood' : 'Меню — ' . $venue['name'];

// Без параметра venue показываем все заведения по очереди и подписываем
// каждое: иначе категории разных столовых («Напитки», «Выпечка») шли бы
// подряд, и было бы непонятно, откуда блюдо.
$shownVenues = $venue !== null ? [$venue] : $venues;
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

<main class="container menu-page">
    <a class="back-link" href="/">← К заведениям</a>

    <?php if ($venue !== null): ?>
        <header class="venue-head">
            <p class="venue-eyebrow">Меню заведения</p>
            <h1 class="page-title"><?= htmlspecialchars($venue['name']) ?></h1>
            <p class="venue-address"><?= htmlspecialchars($venue['address']) ?></p>
        </header>
    <?php else: ?>
        <h1 class="page-title">Меню</h1>
    <?php endif; ?>

    <?php if ($catalogError !== ''): ?>
        <p class="error"><?= htmlspecialchars($catalogError) ?></p>
    <?php endif; ?>

    <?php if ($venue !== null): ?>
        <?php if ($venue['menu_image_url'] !== null || $venue['menu_text'] !== ''): ?>
            <div class="venue-menu">
                <?php if ($venue['menu_image_url'] !== null): ?>
                    <img
                        class="venue-menu-image"
                        src="<?= htmlspecialchars($venue['menu_image_url']) ?>"
                        alt="Меню: <?= htmlspecialchars($venue['name']) ?>"
                        loading="lazy"
                    >
                <?php endif; ?>

                <?php if ($venue['menu_text'] !== ''): ?>
                    <div class="venue-menu-text"><?= nl2br(htmlspecialchars($venue['menu_text'])) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <p class="text-muted">Цены указаны за одну порцию.</p>

    <?php foreach ($shownVenues as $shownVenue): ?>
        <?php if ($venue === null): ?>
            <h2 class="venue-title"><?= htmlspecialchars($shownVenue['name']) ?></h2>
            <p class="venue-address text-muted"><?= htmlspecialchars($shownVenue['address']) ?></p>
        <?php endif; ?>

        <?php foreach ($categories as $category): ?>
            <?php if ($category['venue_id'] !== $shownVenue['id']): ?>
                <?php continue; ?>
            <?php endif; ?>

            <section class="category-block" data-reveal>
                <h2 class="section-title"><?= htmlspecialchars($category['name']) ?></h2>

                <div class="dish-grid">
                    <?php foreach ($dishes as $dish): ?>
                        <?php if ($dish['category_id'] !== $category['id']): ?>
                            <?php continue; ?>
                        <?php endif; ?>

                        <?php
                        // У блюд с сайта администратор заполняет русское название.
                        // Пока перевода нет, показываем название с сайта как есть.
                        $dishName = $dish['name_ru'] !== '' ? $dish['name_ru'] : $dish['name'];
                        ?>

                        <article class="card dish-card">
                            <h3><?= htmlspecialchars($dishName) ?></h3>

                            <p><?= htmlspecialchars($dish['description']) ?></p>
                            <p>
                                <span class="dish-price"><?= formatPrice((float) $dish['price']) ?></span>
                            </p>

                            <form method="post" action="/cart.php" data-cart-add>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="dish_id" value="<?= (int) $dish['id'] ?>">
                                <input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/') ?>">
                                <button class="btn btn-primary" type="submit">В корзину</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endforeach; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
