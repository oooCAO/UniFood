<?php
/**
 * Админка: управление временными слотами (А6).
 *
 * Как и categories.php/dishes.php, показывает слоты одного заведения
 * за раз (выбор сверху через GET venue_id).
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/venues.php';
require_once __DIR__ . '/../../src/pickup_slots.php';

requireRole(ROLE_ADMIN);

$error = '';
// Сообщение об успехе после PRG-редиректа (например, результат генерации
// слотов) приходит через GET и выводится один раз — F5 его не повторяет.
$notice = (string) ($_GET['message'] ?? '');
$venues = listVenues();

$venueId = (int) ($_GET['venue_id'] ?? getDefaultVenueId($venues));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
    } else {
        $action = $_POST['action'] ?? '';
        $venueId = (int) ($_POST['venue_id'] ?? $venueId);
        $date = $_POST['slot_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';
        $capacity = (int) ($_POST['capacity'] ?? 0);

        // Генерация не требует даты/времени/вместимости из формы — это
        // отдельная ветка действий, обрабатываем её раньше общей проверки.
        if ($action === 'generate') {
            try {
                $result = generateSlotsForDate(date('Y-m-d'));
                $message = "Создано: {$result['created']}, уже было: {$result['skipped']}";

                header('Location: /admin/slots.php?venue_id=' . $venueId . '&message=' . rawurlencode($message));
                exit;
            } catch (Throwable $exception) {
                // Текст для админа фиксирован (C11), техническую причину
                // оставляем только в логе сервера.
                error_log('generateSlotsForDate: ' . $exception->getMessage());
                $error = 'Не удалось сгенерировать слоты. Попробуйте ещё раз.';
            }
        }

        if ($date === '' || $startTime === '' || $endTime === '' || $capacity <= 0) {
            $error = 'Дата, время и вместимость (больше нуля) обязательны.';
        } elseif ($startTime >= $endTime) {
            $error = 'Время начала должно быть раньше времени конца.';
        } else {
            switch ($action) {
                case 'add':
                    if (createSlot($venueId, $date, $startTime, $endTime, $capacity) === null) {
                        $error = 'Не удалось добавить слот: заведение не найдено.';
                        break;
                    }

                    header('Location: /admin/slots.php?venue_id=' . $venueId);
                    exit;

                case 'update':
                    $id = (int) ($_POST['id'] ?? 0);
                    $isActive = isset($_POST['is_active']);

                    if ($id <= 0) {
                        $error = 'Слот не найден.';
                    } else {
                        updateSlot($id, $date, $startTime, $endTime, $capacity, $isActive);
                        header('Location: /admin/slots.php?venue_id=' . $venueId);
                        exit;
                    }
                    break;

                default:
                    $error = 'Неизвестное действие.';
            }
        }
    }
}

$slots = $venueId > 0 ? listSlots($venueId) : [];
$pageTitle = 'Слоты получения — админка UniFood';
include __DIR__ . '/admin-header.php';
?>
<h1 class="admin-page-title">Временные слоты</h1>

<?php if ($error !== ''): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if ($notice !== ''): ?>
    <p class="notice"><?= htmlspecialchars($notice) ?></p>
<?php endif; ?>

<?php if (empty($venues)): ?>
    <p class="notice">Сначала нужно добавить заведение.</p>
<?php else: ?>
    <form method="get" action="/admin/slots.php" class="admin-filter-form">
        <label>
            Заведение:
            <select name="venue_id" onchange="this.form.submit()">
                <?php foreach ($venues as $venue): ?>
                    <option value="<?= (int) $venue['id'] ?>" <?= $venue['id'] == $venueId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($venue['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <form method="post" action="/admin/slots.php" class="admin-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
        <input type="hidden" name="action" value="generate">
        <input type="hidden" name="venue_id" value="<?= (int) $venueId ?>">
        <button type="submit">Сгенерировать слоты на сегодня</button>
    </form>

    <ul class="admin-list">
        <?php foreach ($slots as $slot): ?>
            <li>
                <form method="post" action="/admin/slots.php" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $slot['id'] ?>">
                    <input type="hidden" name="venue_id" value="<?= (int) $venueId ?>">
                    <input type="date" name="slot_date" value="<?= htmlspecialchars($slot['slot_date']) ?>" required>
                    <input type="time" name="start_time" value="<?= htmlspecialchars(substr($slot['start_time'], 0, 5)) ?>" required>
                    <input type="time" name="end_time" value="<?= htmlspecialchars(substr($slot['end_time'], 0, 5)) ?>" required>
                    <input type="number" name="capacity" value="<?= (int) $slot['capacity'] ?>" min="1" required>
                    <label>
                        <input type="checkbox" name="is_active" value="1" <?= $slot['is_active'] ? 'checked' : '' ?>>
                        Активен
                    </label>
                    <button type="submit">Сохранить</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>

    <h2 class="admin-section-title">Добавить слот</h2>
    <form method="post" action="/admin/slots.php" class="admin-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="venue_id" value="<?= (int) $venueId ?>">
        <p>
            <label>Дата: <input type="date" name="slot_date" required></label>
        </p>
        <p>
            <label>Начало: <input type="time" name="start_time" required></label>
        </p>
        <p>
            <label>Конец: <input type="time" name="end_time" required></label>
        </p>
        <p>
            <label>Вместимость: <input type="number" name="capacity" min="1" value="20" required></label>
        </p>
        <button type="submit">Добавить</button>
    </form>
<?php endif; ?>

<?php include __DIR__ . '/admin-footer.php'; ?>
