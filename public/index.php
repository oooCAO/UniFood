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

<main class="container">
    <h1 class="page-title">Заведения</h1>
    <p class="text-muted">Выберите столовую или кафе, чтобы посмотреть меню.</p>

    <?php if ($catalogError !== ''): ?>
        <p class="error"><?= htmlspecialchars($catalogError) ?></p>
    <?php endif; ?>

    <div class="venue-grid">
        <?php foreach ($venues as $venue): ?>
            <section class="card">
                <h3><?= htmlspecialchars($venue['name']) ?></h3>
                <p><strong>Адрес:</strong> <?= htmlspecialchars($venue['address']) ?></p>
                <a class="btn" href="/menu.php?venue=<?= (int) $venue['id'] ?>">Смотреть меню</a>
            </section>
        <?php endforeach; ?>
    </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
