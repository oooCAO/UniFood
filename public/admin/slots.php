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
$venues = listVenues();

$venueId = (int) ($_GET['venue_id'] ?? ($venues[0]['id'] ?? 0));

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

        if ($date === '' || $startTime === '' || $endTime === '' || $capacity <= 0) {
            $error = 'Дата, время и вместимость (больше нуля) обязательны.';
        } elseif ($startTime >= $endTime) {
            $error = 'Время начала должно быть раньше времени конца.';
        } else {
            switch ($action) {
                case 'add':
                    createSlot($venueId, $date, $startTime, $endTime, $capacity);
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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Слоты получения — админка UniFood</title>
</head>
<body>
<h1>Временные слоты</h1>

<?php if ($error !== ''): ?>
    <p><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if (empty($venues)): ?>
    <p>Сначала нужно добавить заведение.</p>
<?php else: ?>
    <form method="get" action="/admin/slots.php">
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

    <ul>
        <?php foreach ($slots as $slot): ?>
            <li>
                <form method="post" action="/admin/slots.php">
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

    <h2>Добавить слот</h2>
    <form method="post" action="/admin/slots.php">
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

<p><a href="/admin/venues.php">Заведения</a> · <a href="/admin/categories.php">Категории</a> · <a href="/admin/dishes.php">Блюда</a> · <a href="/admin/users.php">Пользователи</a> · <a href="/">На главную</a></p>
</body>
</html>
