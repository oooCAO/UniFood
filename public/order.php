<?php
/**
 * Страница оформления заказа.
 *
 * Показывает состав корзины с названиями и ценами из базы, даёт выбрать
 * заведение и слот получения и создаёт заказ. После успеха страница
 * перезагружается с ?success=<id заказа> и показывает подтверждение,
 * чтобы обновление страницы не оформляло заказ второй раз.
 *
 * Сама логика работы с заказом лежит в src/order.php.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/order.php';

// Заказ оформляет только вошедший студент, гостю отвечаем 401 как в cart.php.
requireLogin();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
    } else {
        $venueId = (int) ($_POST['venue_id'] ?? 0);
        $slotId = (int) ($_POST['slot_id'] ?? 0);

        $result = createOrder((int) $_SESSION['user_id'], $venueId, $slotId, getCart());

        if ($result['success']) {
            header('Location: /order.php?success=' . $result['order_id']);
            exit;
        }

        $error = $result['error'];
    }
}

// Заказ, который только что оформили: показываем его только владельцу.
$successOrder = null;

if (isset($_GET['success'])) {
    $stmt = getMysqlConnection()->prepare(
        'SELECT orders.id, orders.total_price, venues.name AS venue_name,
                venues.address, pickup_slots.slot_date,
                pickup_slots.start_time, pickup_slots.end_time
         FROM orders
         JOIN venues ON venues.id = orders.venue_id
         JOIN pickup_slots ON pickup_slots.id = orders.slot_id
         WHERE orders.id = ? AND orders.user_id = ?'
    );
    $stmt->execute([(int) $_GET['success'], (int) $_SESSION['user_id']]);
    $successOrder = $stmt->fetch();

    if ($successOrder !== false) {
        $stmt = getMysqlConnection()->prepare(
            'SELECT dishes.name, order_items.quantity, order_items.price
             FROM order_items
             JOIN dishes ON dishes.id = order_items.dish_id
             WHERE order_items.order_id = ?
             ORDER BY order_items.id'
        );
        $stmt->execute([(int) $successOrder['id']]);
        $successOrder['items'] = $stmt->fetchAll();
    }
}

$cart = getCart();
$cartItems = getOrderItemsDetails($cart);
$venues = getActiveVenues();
$slots = getAvailableSlots();

// Если форма вернулась с ошибкой, оставляем выбор пользователя,
// чтобы ему не приходилось выбирать заведение и время заново.
$selectedVenueId = (int) ($_POST['venue_id'] ?? 0);
$selectedSlotId = (int) ($_POST['slot_id'] ?? 0);

// Заранее проверяем состав корзины, чтобы не показывать кнопку оформления,
// если createOrder() всё равно откажет: с недоступным блюдом или
// с блюдами из разных заведений заказ оформить нельзя.
$cartVenueIds = [];
$cartHasUnavailable = false;

foreach ($cart as $dishId => $quantity) {
    $dish = $cartItems[(int) $dishId] ?? null;

    if ($dish === null || (int) $dish['is_available'] !== 1) {
        $cartHasUnavailable = true;
        continue;
    }

    $cartVenueIds[(int) $dish['venue_id']] = true;
}

$cartHasMixedVenues = count($cartVenueIds) > 1;

// Если все блюда из одного заведения, подставляем его в форму:
// так пользователю реже приходится получать ошибку про другое заведение.
if ($selectedVenueId === 0 && count($cartVenueIds) === 1) {
    $selectedVenueId = (int) array_key_first($cartVenueIds);
}

// Слоты других заведений не показываем: блюда корзины должны быть из
// выбранного заведения, иначе createOrder() откажет.
$venueSlots = [];

foreach ($slots as $slot) {
    if ((int) $slot['venue_id'] === $selectedVenueId) {
        $venueSlots[] = $slot;
    }
}

$hasSlots = !empty($venueSlots);
$canSubmitOrder = !empty($cart) && !$cartHasUnavailable && !$cartHasMixedVenues;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Оформление заказа — UniFood</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="container">
<h1 class="page-title">Оформление заказа</h1>

<?php if ($successOrder !== null && $successOrder !== false): ?>
    <p>Заказ №<?= (int) $successOrder['id'] ?> принят. Статус заказа обновит сотрудник.</p>

    <p>
        Место получения: <?= htmlspecialchars($successOrder['venue_name']) ?>,
        <?= htmlspecialchars($successOrder['address']) ?><br>
        Время: <?= htmlspecialchars(date('d.m.Y', strtotime($successOrder['slot_date']))) ?>,
        <?= htmlspecialchars(substr($successOrder['start_time'], 0, 5)) ?>–<?= htmlspecialchars(substr($successOrder['end_time'], 0, 5)) ?>
    </p>

    <table>
        <tr>
            <th>Блюдо</th>
            <th>Количество</th>
            <th>Цена</th>
            <th>Сумма</th>
        </tr>
        <?php foreach ($successOrder['items'] as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td><?= (int) $item['quantity'] ?></td>
                <td><?= number_format((float) $item['price'], 2, '.', '') ?></td>
                <td><?= number_format((float) $item['price'] * (int) $item['quantity'], 2, '.', '') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <p><strong>Итого: <?= number_format((float) $successOrder['total_price'], 2, '.', '') ?></strong></p>

    <p>
        <a class="btn btn-primary" href="/orders.php?id=<?= (int) $successOrder['id'] ?>">Посмотреть заказ</a>
        <a class="btn" href="/orders.php">Мои заказы</a>
        <a class="btn" href="/">На главную</a>
    </p>
<?php elseif ($successOrder === false): ?>
    <p>Заказ не найден.</p>
    <p>
        <a class="btn" href="/orders.php">Мои заказы</a>
        <a class="btn" href="/cart.php">Вернуться в корзину</a>
    </p>
<?php else: ?>
    <?php if ($error !== ''): ?>
        <p><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if (empty($cart)): ?>
        <p>Корзина пуста, оформлять нечего.</p>
        <p><a href="/cart.php">Перейти в корзину</a></p>
    <?php else: ?>
        <table>
            <tr>
                <th>Блюдо</th>
                <th>Цена</th>
                <th>Количество</th>
                <th>Сумма</th>
            </tr>
            <?php
            $total = 0.0;

            foreach ($cart as $dishId => $quantity):
                $dish = $cartItems[(int) $dishId] ?? null;
                ?>
                <tr>
                    <?php if ($dish === null): ?>
                        <td>Блюдо №<?= (int) $dishId ?> (уже нет в меню)</td>
                        <td>—</td>
                        <td><?= (int) $quantity ?></td>
                        <td>—</td>
                    <?php else: ?>
                        <td>
                            <?= htmlspecialchars($dish['name']) ?>
                            <?php if ((int) $dish['is_available'] !== 1): ?>
                                (сейчас недоступно)
                            <?php endif; ?>
                        </td>
                        <td><?= number_format((float) $dish['price'], 2, '.', '') ?></td>
                        <td><?= (int) $quantity ?></td>
                        <?php
                        $sum = (float) $dish['price'] * (int) $quantity;
                        $total += $sum;
                        ?>
                        <td><?= number_format($sum, 2, '.', '') ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>

        <p><strong>Итого: <?= number_format($total, 2, '.', '') ?></strong></p>

        <?php if ($cartHasMixedVenues): ?>
            <p>В корзине блюда из разных заведений. Одним заказом их забрать нельзя: удалите лишние блюда в корзине.</p>
        <?php endif; ?>

        <?php if ($cartHasUnavailable): ?>
            <p>В корзине есть недоступные блюда. Удалите их в корзине, чтобы оформить заказ.</p>
        <?php endif; ?>

        <?php if ($canSubmitOrder && !$hasSlots): ?>
            <p>Нет доступного времени получения для этого заведения.</p>
        <?php endif; ?>

        <?php if ($canSubmitOrder && $hasSlots): ?>
        <form method="post" action="/order.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">

            <p>
                <label for="venue_id">Место получения</label><br>
                <select id="venue_id" name="venue_id" required>
                    <option value="">— выберите заведение —</option>
                    <?php foreach ($venues as $venue): ?>
                        <option value="<?= (int) $venue['id'] ?>"<?= (int) $venue['id'] === $selectedVenueId ? ' selected' : '' ?>>
                            <?= htmlspecialchars($venue['name']) ?>, <?= htmlspecialchars($venue['address']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="slot_id">Время получения</label><br>
                <select id="slot_id" name="slot_id" required>
                    <option value="">— выберите время —</option>
                    <?php
                    // Слоты группируем по заведению, чтобы было видно, к какому
                    // месту получения относится время. Здесь остаются только
                    // слоты выбранного заведения, но группа сохраняет подпись.
                    $slotsByVenue = [];

                    foreach ($venueSlots as $slot) {
                        $slotsByVenue[$slot['venue_name']][] = $slot;
                    }
                    ?>
                    <?php foreach ($slotsByVenue as $venueName => $venueSlots): ?>
                        <optgroup label="<?= htmlspecialchars($venueName) ?>">
                            <?php foreach ($venueSlots as $slot): ?>
                                <?php
                                $slotLabel = date('d.m.Y', strtotime($slot['slot_date']))
                                    . ', ' . substr($slot['start_time'], 0, 5)
                                    . '–' . substr($slot['end_time'], 0, 5);
                                $isFull = (int) $slot['free'] <= 0;
                                ?>
                                <option value="<?= (int) $slot['id'] ?>"
                                        <?= $isFull ? 'disabled' : '' ?>
                                        <?= (int) $slot['id'] === $selectedSlotId ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($slotLabel) ?>
                                    <?= $isFull ? '(мест нет)' : '(свободно ' . (int) $slot['free'] . ')' ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </p>

            <button type="submit">Оформить заказ</button>
        </form>
        <?php endif; ?>

        <p><a class="btn" href="/cart.php">Вернуться в корзину</a></p>
    <?php endif; ?>
<?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
