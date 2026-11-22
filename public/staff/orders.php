<?php
/**
 * Заказы сотрудника (Сот2–Сот6).
 *
 * Без параметра в адресе — список заказов своего заведения с фильтром
 * по статусу. С ?id=<заказ> — состав, время получения и форма смены
 * статуса. Заведение сотрудника берём из users.venue_id, а не из формы.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/staff_orders.php';
// getOrderStatusLabel()/getOrderStatusLabels() — русские подписи статусов,
// уже написаны Разработчиком 1 для страницы студента, дублировать их не нужно.
require_once __DIR__ . '/../../src/order_history.php';

requireRole(ROLE_STAFF);

$staffUserId = (int) $_SESSION['user_id'];
$venueId = getStaffVenueId($staffUserId);

$error = '';
$historyWarning = false;

if ($venueId === null) {
    $error = 'Ваш аккаунт не привязан ни к одному заведению. Обратитесь к администратору.';
}

$orderId = (int) ($_GET['id'] ?? 0);
$showDetails = isset($_GET['id']);
$statusFilter = $_GET['status'] ?? '';

if ($error === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
    } else {
        $postOrderId = (int) ($_POST['order_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';

        $result = changeOrderStatus($venueId, $postOrderId, $newStatus, $staffUserId);

        if (!$result['ok']) {
            $error = $result['error'];
        } else {
            if ($result['historyWarning']) {
                $historyWarning = true;
            }
            header('Location: /staff/orders.php?id=' . $postOrderId . ($historyWarning ? '&history_warning=1' : ''));
            exit;
        }
    }
}

if (isset($_GET['history_warning'])) {
    $historyWarning = true;
}

$order = null;
$orders = [];
$transitions = getStatusTransitions();

if ($error === '') {
    if ($showDetails) {
        $order = getOrderForStaff($venueId, $orderId);

        if ($order === null) {
            http_response_code(404);
        }
    } else {
        $orders = listOrdersForStaff($venueId, $statusFilter !== '' ? $statusFilter : null);
    }
}

$pageTitle = $showDetails ? 'Заказ — UniFood' : 'Заказы — UniFood';
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
<?php include __DIR__ . '/../header.php'; ?>

<main class="container">
    <h1 class="page-title"><?= $showDetails ? 'Заказ' : 'Заказы моего заведения' ?></h1>

    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php if ($showDetails): ?>
            <p><a class="btn" href="/staff/orders.php">К списку заказов</a></p>
        <?php endif; ?>
    <?php elseif ($showDetails && $order === null): ?>
        <p class="error">Заказ не найден или относится к другому заведению.</p>
        <p><a class="btn" href="/staff/orders.php">К списку заказов</a></p>
    <?php elseif ($showDetails): ?>
        <?php if ($historyWarning): ?>
            <p class="error">Статус обновлён, но запись в историю не сохранена (ошибка MongoDB). Сообщите администратору.</p>
        <?php endif; ?>

        <section class="card">
            <h2 class="section-title">Заказ №<?= (int) $order['id'] ?></h2>

            <p>
                <strong>Статус:</strong> <?= htmlspecialchars(getOrderStatusLabel($order['status'])) ?><br>
                <strong>Студент:</strong> <?= htmlspecialchars($order['student_name']) ?> (<?= htmlspecialchars($order['student_email']) ?>)<br>
                <strong>Оформлен:</strong> <?= htmlspecialchars(date('d.m.Y H:i', strtotime($order['created_at']))) ?><br>
                <strong>Время получения:</strong>
                <?= htmlspecialchars(date('d.m.Y', strtotime($order['slot_date']))) ?>,
                <?= htmlspecialchars(substr($order['start_time'], 0, 5)) ?>–<?= htmlspecialchars(substr($order['end_time'], 0, 5)) ?>
            </p>

            <table>
                <tr>
                    <th>Блюдо</th>
                    <th>Количество</th>
                    <th>Цена</th>
                </tr>
                <?php foreach ($order['items'] as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= (int) $item['quantity'] ?></td>
                        <td><?= number_format((float) $item['price'], 2, '.', '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <p><strong>Итого: <?= number_format((float) $order['total_price'], 2, '.', '') ?></strong></p>

            <?php $nextStatuses = $transitions[$order['status']] ?? []; ?>
            <?php if (!empty($nextStatuses)): ?>
                <form method="post" action="/staff/orders.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <label>
                        Новый статус:
                        <select name="new_status">
                            <?php foreach ($nextStatuses as $status): ?>
                                <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(getOrderStatusLabel($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit">Сохранить</button>
                </form>
            <?php else: ?>
                <p class="notice">Статус окончательный, менять его больше нельзя.</p>
            <?php endif; ?>
        </section>

        <p><a class="btn" href="/staff/orders.php">К списку заказов</a></p>
    <?php else: ?>
        <form method="get" action="/staff/orders.php">
            <label>
                Статус:
                <select name="status" onchange="this.form.submit()">
                    <option value="">Все</option>
                    <?php foreach (getOrderStatusLabels() as $code => $label): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= $statusFilter === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>

        <?php if (empty($orders)): ?>
            <p class="notice">Заказов по выбранному фильтру нет.</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>№</th>
                    <th>Студент</th>
                    <th>Время получения</th>
                    <th>Статус</th>
                    <th>Сумма</th>
                    <th></th>
                </tr>
                <?php foreach ($orders as $item): ?>
                    <tr>
                        <td><?= (int) $item['id'] ?></td>
                        <td><?= htmlspecialchars($item['student_name']) ?></td>
                        <td>
                            <?= htmlspecialchars(date('d.m.Y', strtotime($item['slot_date']))) ?>,
                            <?= htmlspecialchars(substr($item['start_time'], 0, 5)) ?>–<?= htmlspecialchars(substr($item['end_time'], 0, 5)) ?>
                        </td>
                        <td><?= htmlspecialchars(getOrderStatusLabel($item['status'])) ?></td>
                        <td><?= number_format((float) $item['total_price'], 2, '.', '') ?></td>
                        <td><a href="/staff/orders.php?id=<?= (int) $item['id'] ?>">Открыть</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
</body>
</html>
