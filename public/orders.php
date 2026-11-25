<?php
/**
 * Страница «Мои заказы».
 *
 * Без параметра в адресе показывает историю заказов студента (С13),
 * с ?id=<заказ> — подробности одного заказа (С12). Обе части берут данные
 * из src/order_history.php, который ищет заказы только текущего пользователя.
 *
 * Чужой заказ выглядит как несуществующий: отдаём 404 и не показываем
 * ни суммы, ни состава.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/order_history.php';
require_once __DIR__ . '/../src/format.php';

// История заказов есть только у вошедшего пользователя.
requireLogin();

$userId = (int) $_SESSION['user_id'];
$orderId = (int) ($_GET['id'] ?? 0);
$showDetails = isset($_GET['id']);

$error = '';
$order = null;
$orders = [];
$history = [];
$historyError = '';

try {
    if ($showDetails) {
        $order = getUserOrderWithItems($userId, $orderId);

        if ($order === null) {
            // Заказа нет или он чужой — ответ одинаковый, чтобы по коду
            // ответа нельзя было узнать, существует ли чужой заказ.
            http_response_code(404);
        } else {
            // История запрашивается только для своего заказа и только после
            // того, как заказ найден. Сбой Mongo не должен ломать страницу:
            // статус и состав уже есть в MySQL, показываем их без истории.
            try {
                $history = getOrderStatusHistory($orderId);
            } catch (Throwable $e) {
                error_log('Не удалось прочитать историю статусов заказа: ' . $e->getMessage());
                $historyError = 'Не удалось загрузить историю изменений. Попробуйте обновить страницу позже.';
            }
        }
    } else {
        $orders = getUserOrdersWithItems($userId);
    }
} catch (PDOException $e) {
    // Текст ошибки базы видит только администратор в логах.
    error_log('Ошибка при чтении заказов пользователя: ' . $e->getMessage());
    http_response_code(500);
    $error = 'Не удалось загрузить заказы из-за ошибки базы данных. Попробуйте обновить страницу.';
}

$pageTitle = $showDetails ? 'Заказ — UniFood' : 'Мои заказы — UniFood';
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
    <h1 class="page-title"><?= $showDetails ? 'Заказ' : 'Мои заказы' ?></h1>

    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
        <p><a class="btn" href="/orders.php">К моим заказам</a></p>
    <?php elseif ($showDetails && $order === null): ?>
        <p class="error">Заказ не найден. Возможно, ссылка устарела или заказ оформлен не с этого аккаунта.</p>
        <p><a class="btn" href="/orders.php">Все мои заказы</a></p>
    <?php elseif ($showDetails): ?>
        <section class="card">
            <h2 class="section-title">Заказ №<?= (int) $order['id'] ?></h2>

            <p>
                <strong>Статус:</strong> <?= htmlspecialchars(getOrderStatusLabel($order['status'])) ?><br>
                <strong>Оформлен:</strong> <?= htmlspecialchars(date('d.m.Y', strtotime($order['created_at']))) ?><br>
                <strong>Заведение:</strong> <?= htmlspecialchars($order['venue_name']) ?>,
                <?= htmlspecialchars($order['address']) ?><br>
                <strong>Время получения:</strong>
                <?= htmlspecialchars(date('d.m.Y', strtotime($order['slot_date']))) ?>,
                <?= htmlspecialchars(substr($order['start_time'], 0, 5)) ?>–<?= htmlspecialchars(substr($order['end_time'], 0, 5)) ?>
            </p>

            <table>
                <tr>
                    <th>Блюдо</th>
                    <th>Количество</th>
                    <th>Цена</th>
                    <th>Сумма</th>
                </tr>
                <?php foreach ($order['items'] as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= (int) $item['quantity'] ?></td>
                        <td><?= formatPrice((float) $item['price']) ?></td>
                        <td><?= formatPrice((float) $item['price'] * (int) $item['quantity']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <p><strong>Итого: <?= formatPrice((float) $order['total_price']) ?></strong></p>
        </section>

        <section class="card">
            <h2 class="section-title">История статусов</h2>

            <?php if ($historyError !== ''): ?>
                <p class="notice"><?= htmlspecialchars($historyError) ?></p>
            <?php elseif (empty($history)): ?>
                <p class="notice">История изменений пока пуста.</p>
            <?php else: ?>
                <table>
                    <tr>
                        <th>Когда (Минск)</th>
                        <th>Было</th>
                        <th>Стало</th>
                        <th>Кто изменил</th>
                    </tr>
                    <?php foreach ($history as $entry): ?>
                        <tr>
                            <td><?= htmlspecialchars($entry['changed_at']->format('d.m.Y H:i')) ?></td>
                            <td><?= htmlspecialchars(getOrderStatusLabel($entry['old_status'])) ?></td>
                            <td><?= htmlspecialchars(getOrderStatusLabel($entry['new_status'])) ?></td>
                            <td><?= htmlspecialchars($entry['changed_by_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </section>

        <p><a class="btn" href="/orders.php">Все мои заказы</a></p>
    <?php elseif (empty($orders)): ?>
        <p class="notice">У вас пока нет заказов. Выберите блюда в меню и оформите первый заказ.</p>
        <p>
            <a class="btn btn-primary" href="/menu.php">Перейти в меню</a>
            <a class="btn" href="/">К заведениям</a>
        </p>
    <?php else: ?>
        <table>
            <tr>
                <th>№</th>
                <th>Дата</th>
                <th>Заведение</th>
                <th>Время получения</th>
                <th>Статус</th>
                <th>Состав</th>
                <th>Сумма</th>
                <th></th>
            </tr>
            <?php foreach ($orders as $item): ?>
                <tr>
                    <td><?= (int) $item['id'] ?></td>
                    <td><?= htmlspecialchars(date('d.m.Y', strtotime($item['created_at']))) ?></td>
                    <td><?= htmlspecialchars($item['venue_name']) ?></td>
                    <td>
                        <?= htmlspecialchars(date('d.m.Y', strtotime($item['slot_date']))) ?>,
                        <?= htmlspecialchars(substr($item['start_time'], 0, 5)) ?>–<?= htmlspecialchars(substr($item['end_time'], 0, 5)) ?>
                    </td>
                    <td><?= htmlspecialchars(getOrderStatusLabel($item['status'])) ?></td>
                    <td>
                        <?php
                        // Состав собираем в строку «Плов ×2, Чай ×1»,
                        // чтобы таблица истории не разъезжалась по высоте.
                        $parts = [];

                        foreach ($item['items'] as $position) {
                            $parts[] = $position['name'] . ' ×' . (int) $position['quantity'];
                        }

                        echo htmlspecialchars(implode(', ', $parts));
                        ?>
                    </td>
                    <td><?= formatPrice((float) $item['total_price']) ?></td>
                    <td><a href="/orders.php?id=<?= (int) $item['id'] ?>">Посмотреть</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
