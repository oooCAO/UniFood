<?php
/**
 * Админка: импорт меню с market.grsu.by (раздел 1.5 задачи).
 *
 * Последний запуск (журнал пишет src/menu_import.php при каждом вызове
 * runMenuImport()), кнопка «Обновить сейчас» и редактирование русского
 * названия для блюд, пришедших с сайта (source = market).
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/menu_import.php';

requireRole(ROLE_ADMIN);

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'refresh':
                // Запрос к внешнему сайту занимает несколько секунд — это
                // синхронный вызов в рамках той же страницы, отдельного
                // фона в проекте нет.
                $result = runMenuImport(false);

                if ($result['success']) {
                    $notice = "Импорт выполнен: добавлено {$result['added']}, обновлено {$result['updated']}, скрыто {$result['hidden']}.";
                } else {
                    $error = 'Импорт не выполнен: ' . $result['error'];
                }
                break;

            case 'update_name_ru':
                $dishId = (int) ($_POST['dish_id'] ?? 0);
                $nameRu = (string) ($_POST['name_ru'] ?? '');

                if ($dishId <= 0 || !updateDishNameRu($dishId, $nameRu)) {
                    $source = $dishId > 0 ? getDishSource($dishId) : null;

                    if ($source === null) {
                        $error = 'Не удалось сохранить название: блюдо не найдено.';
                    } elseif ($source !== 'market') {
                        $error = 'Русское название задаётся только для блюд с сайта.';
                    } else {
                        $error = 'Не удалось сохранить название.';
                    }
                } else {
                    header('Location: /admin/menu-import.php');
                    exit;
                }
                break;

            default:
                $error = 'Неизвестное действие.';
        }
    }
}

$lastRun = getLatestMenuImportRun();
$dishes = listMarketDishes();

$statusLabels = [
    'running' => 'Выполняется',
    'success' => 'Успешно',
    'failed' => 'Ошибка',
];

$pageTitle = 'Импорт меню — админка UniFood';
include __DIR__ . '/admin-header.php';
?>
<h1 class="admin-page-title">Импорт меню</h1>

<?php if ($error !== ''): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if ($notice !== ''): ?>
    <p class="notice"><?= htmlspecialchars($notice) ?></p>
<?php endif; ?>

<h2 class="admin-section-title">Последний запуск</h2>

<?php if ($lastRun === null): ?>
    <p class="notice">Импорт ещё не запускался.</p>
<?php else: ?>
    <ul class="admin-list admin-stats-summary">
        <li>Начат: <?= htmlspecialchars($lastRun['started_at']) ?></li>
        <li>Завершён: <?= $lastRun['finished_at'] !== null ? htmlspecialchars($lastRun['finished_at']) : 'не завершён' ?></li>
        <li>Статус: <?= htmlspecialchars($statusLabels[$lastRun['status']] ?? $lastRun['status']) ?></li>
        <li>Добавлено: <?= (int) $lastRun['dishes_added'] ?>, обновлено: <?= (int) $lastRun['dishes_updated'] ?>, скрыто: <?= (int) $lastRun['dishes_hidden'] ?></li>
        <?php if ($lastRun['error_text'] !== null): ?>
            <li>Ошибка: <?= htmlspecialchars($lastRun['error_text']) ?></li>
        <?php endif; ?>
    </ul>
<?php endif; ?>

<form method="post" action="/admin/menu-import.php" class="admin-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
    <input type="hidden" name="action" value="refresh">
    <button type="submit">Обновить сейчас</button>
</form>

<h2 class="admin-section-title">Русские названия блюд</h2>

<?php if (empty($dishes)): ?>
    <p class="notice">Пока нет импортированных блюд.</p>
<?php else: ?>
    <ul class="admin-list">
        <?php foreach ($dishes as $dish): ?>
            <li>
                <?= htmlspecialchars($dish['venue_name']) ?> — <?= htmlspecialchars($dish['name']) ?> (<?= number_format((float) $dish['price'], 2, '.', '') ?>)
                <form method="post" action="/admin/menu-import.php" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="update_name_ru">
                    <input type="hidden" name="dish_id" value="<?= (int) $dish['id'] ?>">
                    <input type="text" name="name_ru" value="<?= htmlspecialchars($dish['name_ru'] ?? '') ?>" placeholder="Русское название">
                    <button type="submit">Сохранить</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php include __DIR__ . '/admin-footer.php'; ?>
