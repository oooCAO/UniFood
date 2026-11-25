<?php
/**
 * Страница оформления и изменения заказа.
 *
 * Без параметров показывает состав корзины, даёт выбрать заведение и слот
 * получения и создаёт заказ; после успеха перезагружается с ?success=<id>
 * и показывает подтверждение, чтобы обновление страницы не оформляло заказ
 * второй раз. С ?edit=<id> — форма изменения уже оформленного заказа
 * (слот и состав), пока его статус new (П.9): отдельный режим страницы,
 * а не расширение формы создания, потому что данные берутся не из корзины,
 * а из самого заказа.
 *
 * Сама логика работы с заказом лежит в src/order.php.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/order.php';
require_once __DIR__ . '/../src/format.php';

// Заказ оформляет и меняет только вошедший студент, гостю — 401 как в cart.php.
requireLogin();

$userId = (int) $_SESSION['user_id'];
$isEditMode = isset($_GET['edit']);
$editOrderId = $isEditMode ? (int) $_GET['edit'] : 0;

$error = '';

if ($isEditMode) {
    $editOrder = getUserOrderForEdit($userId, $editOrderId);

    if ($editOrder === null) {
        // Чужой, несуществующий и уже не new заказ выглядят одинаково —
        // как в orders.php?id=, чтобы по ответу нельзя было узнать,
        // какой из трёх случаев это на самом деле.
        http_response_code(404);
    }

    if ($editOrder !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
        if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
            $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
        } else {
            $slotId = (int) ($_POST['slot_id'] ?? 0);
            $rawItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
            $newDishId = (int) ($_POST['new_dish_id'] ?? 0);
            $newDishQuantity = (int) ($_POST['new_dish_quantity'] ?? 0);
            $items = parseOrderItemsFromRequest($rawItems, $newDishId, $newDishQuantity);

            $result = updateOrderByStudent($userId, $editOrderId, $slotId, $items);

            if ($result['ok']) {
                $redirect = '/orders.php?id=' . $editOrderId . '&updated=1';

                if ($result['historyWarning']) {
                    $redirect .= '&historyWarning=1';
                }

                header('Location: ' . $redirect);
                exit;
            }

            $error = $result['error'];
            // Заказ мог не измениться (ошибка), но на всякий случай освежаем
            // данные — вдруг кто-то успел что-то поменять параллельно.
            $editOrder = getUserOrderForEdit($userId, $editOrderId);
        }
    }

    if ($editOrder !== null) {
        $editVenueDishes = getAvailableDishesForVenue((int) $editOrder['venue_id']);

        // Форма меняет слот только на сегодняшний (решение заказчика, П.8),
        // поэтому слоты других дней из общего списка отфильтровываем здесь же,
        // а не показываем варианты, которые сервер потом всё равно отклонит.
        $today = date('Y-m-d');
        $editVenueSlots = [];

        foreach (getAvailableSlots() as $slot) {
            if ((int) $slot['venue_id'] !== (int) $editOrder['venue_id'] || $slot['slot_date'] !== $today) {
                continue;
            }

            // Текущий слот заказа уже занят им самим: в isSlotAvailableForUpdate()
            // этот заказ исключается из подсчёта, поэтому и здесь показываем
            // на одно свободное место больше, иначе свой же слот выглядел бы
            // как заполненный «мест нет».
            if ((int) $slot['id'] === (int) $editOrder['slot_id']) {
                $slot['free'] = (int) $slot['free'] + 1;
            }

            $editVenueSlots[] = $slot;
        }

        $editTotal = 0.0;

        foreach ($editOrder['items'] as $item) {
            $editTotal += (float) $item['price'] * (int) $item['quantity'];
        }
    }

    $pageTitle = 'Изменение заказа — UniFood';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
            $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
        } else {
            $venueId = (int) ($_POST['venue_id'] ?? 0);
            $slotId = (int) ($_POST['slot_id'] ?? 0);

            $result = createOrder($userId, $venueId, $slotId, getCart());

            if ($result['success']) {
                header('Location: /order.php?success=' . $result['order_id']);
                exit;
            }

            $error = $result['error'];
        }
    }

    $pageTitle = 'Оформление заказа — UniFood';
}

// Заказ, который только что оформили: показываем его только владельцу.
// Это и всё, что ниже до конца блока — данные только для режима создания
// заказа, в режиме редактирования (?edit=) они не используются.
$successOrder = null;

if (!$isEditMode && isset($_GET['success'])) {
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

$cart = [];
$cartItems = [];
$venues = [];
$slots = [];
$selectedVenueId = 0;
$selectedSlotId = 0;
$cartHasMixedVenues = false;
$cartHasUnavailable = false;
$hasSlots = false;
$canSubmitOrder = false;

if (!$isEditMode) {
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
}
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
<h1 class="page-title"><?= $isEditMode ? 'Изменение заказа' : 'Оформление заказа' ?></h1>

<?php if ($isEditMode): ?>
    <?php if ($editOrder === null): ?>
        <p class="error">Заказ не найден, уже не новый или оформлен не с этого аккаунта. Изменить его нельзя.</p>
        <p><a class="btn" href="/orders.php">Мои заказы</a></p>
    <?php else: ?>
        <?php if ($error !== ''): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <p>
            <strong>Заведение:</strong> <?= htmlspecialchars($editOrder['venue_name']) ?>
            (поменять заведение нельзя — только время и состав)
        </p>

        <form method="post" action="/order.php?edit=<?= (int) $editOrder['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="update">

            <table>
                <tr>
                    <th>Блюдо</th>
                    <th>Цена</th>
                    <th>Количество</th>
                </tr>
                <?php foreach ($editOrder['items'] as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= formatPrice((float) $item['price']) ?></td>
                        <td>
                            <input type="number" name="items[<?= (int) $item['dish_id'] ?>]"
                                   min="0" max="99" value="<?= (int) $item['quantity'] ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p>Чтобы убрать блюдо из заказа, поставьте у него количество 0.</p>

            <?php if (!empty($editVenueDishes)): ?>
                <p>
                    <label for="new_dish_id">Добавить блюдо</label><br>
                    <select id="new_dish_id" name="new_dish_id">
                        <option value="">— не добавлять —</option>
                        <?php foreach ($editVenueDishes as $dish): ?>
                            <option value="<?= (int) $dish['id'] ?>">
                                <?= htmlspecialchars($dish['name']) ?>
                                (<?= formatPrice((float) $dish['price']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="new_dish_quantity" min="1" max="99" value="1">
                </p>
            <?php endif; ?>

            <p>
                <label for="slot_id">Время получения</label><br>
                <?php if (empty($editVenueSlots)): ?>
                    <?php
                    // Без этого select не отправится вовсе — сервер всё равно
                    // отклонит slot_id=0, но сообщение понятнее, чем пустой список.
                    ?>
                    <span>На сегодня нет доступного времени получения.</span>
                <?php else: ?>
                    <select id="slot_id" name="slot_id" required>
                        <?php foreach ($editVenueSlots as $slot): ?>
                            <?php
                            $slotLabel = substr($slot['start_time'], 0, 5) . '–' . substr($slot['end_time'], 0, 5);
                            $isFull = (int) $slot['free'] <= 0;
                            $isCurrent = (int) $slot['id'] === (int) $editOrder['slot_id'];
                            ?>
                            <option value="<?= (int) $slot['id'] ?>"
                                    <?= $isFull && !$isCurrent ? 'disabled' : '' ?>
                                    <?= $isCurrent ? ' selected' : '' ?>>
                                <?= htmlspecialchars($slotLabel) ?>
                                <?= $isFull && !$isCurrent ? '(мест нет)' : '(свободно ' . (int) $slot['free'] . ')' ?>
                                <?= $isCurrent ? '— текущее время заказа' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </p>

            <p><strong>Текущий итог: <?= formatPrice($editTotal) ?></strong>
                (после изменения сумма будет пересчитана)</p>

            <?php if (!empty($editVenueSlots)): ?>
                <button type="submit">Сохранить изменения</button>
            <?php endif; ?>
        </form>

        <p>Изменить заказ можно, пока сотрудник его не подтвердил.</p>
        <p><a class="btn" href="/orders.php?id=<?= (int) $editOrder['id'] ?>">Отмена, вернуться к заказу</a></p>
    <?php endif; ?>
<?php else: ?>
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
                <td><?= formatPrice((float) $item['price']) ?></td>
                <td><?= formatPrice((float) $item['price'] * (int) $item['quantity']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <p><strong>Итого: <?= formatPrice((float) $successOrder['total_price']) ?></strong></p>

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
                        <td><?= formatPrice((float) $dish['price']) ?></td>
                        <td><?= (int) $quantity ?></td>
                        <?php
                        $sum = (float) $dish['price'] * (int) $quantity;
                        $total += $sum;
                        ?>
                        <td><?= formatPrice($sum) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>

        <p><strong>Итого: <?= formatPrice($total) ?></strong></p>

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
<?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
