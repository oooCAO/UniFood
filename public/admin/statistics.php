<?php
/**
 * Админка: статистика (А7).
 *
 * Фильтр по заведению (необязательно) и диапазону дат (необязательно),
 * без фильтров — за всё время по всем заведениям. Только GET-параметры,
 * форм на запись здесь нет.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/venues.php';
require_once __DIR__ . '/../../src/statistics.php';
// formatPrice() — единый формат цен в BYN для всего сайта (П.5).
require_once __DIR__ . '/../../src/format.php';

requireRole(ROLE_ADMIN);

$venues = listVenues();

$venueId = (int) ($_GET['venue_id'] ?? 0);
$venueId = $venueId > 0 ? $venueId : null;

$dateFrom = trim($_GET['date_from'] ?? '');
$dateFrom = $dateFrom !== '' ? $dateFrom : null;

$dateTo = trim($_GET['date_to'] ?? '');
$dateTo = $dateTo !== '' ? $dateTo : null;

$error = '';
$orderStats = ['orders_count' => 0, 'revenue' => 0.0];
$topDishes = [];
$averageMinutes = null;

try {
    $orderStats = getOrderStats($venueId, $dateFrom, $dateTo);
    $topDishes = getTopDishes($venueId, $dateFrom, $dateTo);
    $averageMinutes = getAverageFulfillmentMinutes($venueId, $dateFrom, $dateTo);
} catch (Throwable $e) {
    error_log('Ошибка при расчёте статистики: ' . $e->getMessage());
    $error = 'Не удалось посчитать статистику. Попробуйте обновить страницу.';
}
$pageTitle = 'Статистика — админка UniFood';
include __DIR__ . '/admin-header.php';
?>
<h1 class="admin-page-title">Статистика</h1>

<form method="get" action="/admin/statistics.php" class="admin-filter-form">
    <label>
        Заведение:
        <select name="venue_id">
            <option value="0">Все</option>
            <?php foreach ($venues as $venue): ?>
                <option value="<?= (int) $venue['id'] ?>" <?= $venueId === (int) $venue['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($venue['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        С: <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom ?? '') ?>">
    </label>
    <label>
        По: <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo ?? '') ?>">
    </label>
    <button type="submit">Показать</button>
</form>

<?php if ($error !== ''): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php else: ?>
    <p class="notice">Учитываются только выданные заказы (статус «Получен»).</p>

    <ul class="admin-list admin-stats-summary">
        <li>Заказов: <?= (int) $orderStats['orders_count'] ?></li>
        <li>Выручка: <?= formatPrice((float) $orderStats['revenue']) ?></li>
        <li>
            Среднее время от оформления до выдачи:
            <?= $averageMinutes !== null ? number_format($averageMinutes, 1, '.', '') . ' мин.' : 'нет данных' ?>
        </li>
    </ul>

    <h2 class="admin-section-title">Популярные блюда</h2>
    <?php if (empty($topDishes)): ?>
        <p class="notice">За выбранный период данных нет.</p>
    <?php else: ?>
        <ul class="admin-list">
            <?php foreach ($topDishes as $dish): ?>
                <li><?= htmlspecialchars($dish['name']) ?> — <?= (int) $dish['total_quantity'] ?> порций</li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/admin-footer.php'; ?>
