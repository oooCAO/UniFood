<?php
/**
 * Страница «Меню на сегодня».
 *
 * Показывает официальное меню столовых ГрГУ с внешнего сайта
 * market.grsu.by. Русская версия сайта отдаёт меню картинками, поэтому
 * страница только показывает названия столовых и сами изображения.
 * Данные загружает и разбирает src/external_menu.php, готовый разбор
 * на час сохраняется в src/today_menu_cache.php, чтобы не ждать внешний сайт.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/today_menu_cache.php';

// Шапка обращается к сессии, поэтому стартуем её до вывода HTML.
startSession();

$menu = getTodayMenu();
$canteens = $menu['canteens'];
$menuError = $menu['error'];

$pageTitle = 'Меню на сегодня — UniFood';
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
    <h1 class="page-title">Меню на сегодня</h1>
    <p class="text-muted today-menu-source">
        Официальное меню столовых ГрГУ.
        Источник: <a href="https://market.grsu.by/today-menu" target="_blank" rel="noopener">market.grsu.by/today-menu</a>.
    </p>

    <?php if ($menuError !== '' && $canteens === []): ?>
        <p class="error"><?= htmlspecialchars($menuError) ?></p>
    <?php else: ?>
        <?php if ($menuError !== ''): ?>
            <p class="notice"><?= htmlspecialchars($menuError) ?></p>
        <?php endif; ?>

        <?php if ($canteens === []): ?>
            <p class="notice">На сегодня меню нет. Загляните позже или посмотрите <a href="/menu.php">обычное меню</a>.</p>
        <?php else: ?>
            <div class="today-menu-list">
                <?php foreach ($canteens as $canteen): ?>
                    <article class="card today-menu-card">
                        <h2 class="today-menu-canteen"><?= htmlspecialchars($canteen['name']) ?></h2>

                        <div class="today-menu-images">
                            <?php foreach ($canteen['images'] as $imageIndex => $imageUrl): ?>
                                <?php $safeImageUrl = htmlspecialchars($imageUrl); ?>
                                <a class="today-menu-image-link" href="<?= $safeImageUrl ?>" target="_blank" rel="noopener">
                                    <img
                                        class="today-menu-image"
                                        src="<?= $safeImageUrl ?>"
                                        alt="Меню столовой «<?= htmlspecialchars($canteen['name']) ?>», фото <?= (int) $imageIndex + 1 ?>"
                                        loading="lazy"
                                    >
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
