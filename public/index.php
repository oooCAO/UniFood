<?php
/**
 * Главная страница.
 *
 * Показывает список заведений, где студент может сделать предзаказ.
 * Список берётся из базы через catalog-data.php.
 */

require_once __DIR__ . '/catalog-data.php';
require_once __DIR__ . '/../src/auth.php';

// Сессия нужна для CSRF-токена и счётчика корзины, поэтому стартуем её до вывода HTML.
startSession();

$pageTitle = 'UniFood — заведения';
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

<main class="container home-page">
    <section class="hero">
        <div class="hero-copy">
            <p class="hero-eyebrow">Столовые и кафе ГрГУ</p>
            <h1 class="hero-title">Обед без очереди — забирайте, когда удобно</h1>
            <p class="hero-subtitle">
                Выберите заведение, соберите заказ и приходите к назначенному времени.
                Мы приготовим заранее, а вам останется только забрать.
            </p>
            <div class="hero-actions">
                <a class="btn btn-primary btn-lg" href="#venues">Выбрать заведение</a>
                <a class="btn btn-lg" href="/menu.php">Смотреть меню</a>
            </div>
        </div>

        <!-- Декоративная композиция: не несёт информации для чтения с экрана. -->
        <div class="hero-art" aria-hidden="true">
            <div class="hero-plate">
                <svg class="hero-bowl" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3.5 11h17a8.5 8.5 0 0 1-17 0Z"></path>
                    <path d="M2.5 20h19"></path>
                    <path d="M8.5 7c0-1.3.7-2.1 1.7-3"></path>
                    <path d="M13 7c0-1.3.7-2.1 1.7-3"></path>
                </svg>
            </div>
            <span class="hero-chip hero-chip--one">Самовывоз</span>
            <span class="hero-chip hero-chip--two">Без очереди</span>
            <span class="hero-chip hero-chip--three">Слоты на сегодня</span>
        </div>
    </section>

    <?php if ($catalogError !== ''): ?>
        <p class="error"><?= htmlspecialchars($catalogError) ?></p>
    <?php endif; ?>

    <section class="venues-section" id="venues">
        <div class="section-head">
            <div>
                <h2 class="section-title">Заведения</h2>
                <p class="text-muted">Выберите столовую или кафе, чтобы посмотреть меню.</p>
            </div>
        </div>

        <div class="venue-grid">
            <?php foreach ($venues as $venue): ?>
                <section class="card venue-card" data-reveal>
                    <span class="venue-mark" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 11h16a8 8 0 0 1-16 0Z"></path>
                            <path d="M3 20h18"></path>
                        </svg>
                    </span>
                    <h3><?= htmlspecialchars($venue['name']) ?></h3>
                    <p class="venue-card-address"><?= htmlspecialchars($venue['address']) ?></p>
                    <a class="btn btn-primary" href="/menu.php?venue=<?= (int) $venue['id'] ?>">Смотреть меню</a>
                </section>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
