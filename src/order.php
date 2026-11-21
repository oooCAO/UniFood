<?php
/**
 * Оформление заказа студента.
 *
 * Здесь собраны функции для страницы order.php: список заведений, свободные
 * слоты получения, состав корзины с названиями и ценами из таблицы dishes
 * и создание заказа в транзакции. Страница только показывает данные
 * и вызывает эти функции.
 *
 * Корзина лежит в сессии (src/cart.php) и хранит лишь id блюда и количество,
 * поэтому названия, цены и принадлежность к заведению всегда берём из БД.
 */

require_once __DIR__ . '/db_mysql.php';
require_once __DIR__ . '/cart.php';

/**
 * Возвращает активные заведения, где можно забрать заказ.
 *
 * @return array Список заведений: id, name, address.
 */
function getActiveVenues(): array
{
    $stmt = getMysqlConnection()->query(
        'SELECT id, name, address FROM venues WHERE is_active = 1 ORDER BY name'
    );

    return $stmt->fetchAll();
}

/**
 * Возвращает активные слоты получения на будущее время.
 *
 * Слот считается будущим, пока не началось его время: если сейчас 12:05,
 * слот 12:00–12:15 уже не показываем. Места считаем по заказам, как записано
 * в схеме БД: отменённые заказы место не занимают.
 *
 * Слоты без свободных мест тоже возвращаем: страница показывает их
 * как недоступные, а createOrder() всё равно проверит вместимость заново.
 *
 * @return array Слоты: id, venue_id, venue_name, slot_date, start_time,
 *               end_time, capacity, taken, free.
 */
function getAvailableSlots(): array
{
    $sql = 'SELECT pickup_slots.id, pickup_slots.venue_id, pickup_slots.slot_date,
                   pickup_slots.start_time, pickup_slots.end_time, pickup_slots.capacity,
                   venues.name AS venue_name,
                   COUNT(orders.id) AS taken
            FROM pickup_slots
            JOIN venues ON venues.id = pickup_slots.venue_id
            -- LEFT JOIN, чтобы слот без заказов тоже попал в список.
            LEFT JOIN orders
                   ON orders.slot_id = pickup_slots.id AND orders.status <> \'cancelled\'
            WHERE pickup_slots.is_active = 1
              AND venues.is_active = 1
              AND (
                    pickup_slots.slot_date > CURDATE()
                    OR (pickup_slots.slot_date = CURDATE() AND pickup_slots.start_time > CURTIME())
              )
            GROUP BY pickup_slots.id, pickup_slots.venue_id, pickup_slots.slot_date,
                     pickup_slots.start_time, pickup_slots.end_time, pickup_slots.capacity,
                     venues.name
            ORDER BY pickup_slots.slot_date, pickup_slots.start_time, venues.name';

    $stmt = getMysqlConnection()->query($sql);
    $slots = $stmt->fetchAll();

    foreach ($slots as $index => $slot) {
        $taken = (int) $slot['taken'];
        $capacity = (int) $slot['capacity'];

        $slots[$index]['taken'] = $taken;
        $slots[$index]['free'] = $capacity - $taken;
    }

    return $slots;
}

/**
 * Достаёт из БД блюда корзины: название, цену, доступность и заведение.
 *
 * Один запрос на всю корзину, чтобы не ходить в базу за каждым блюдом.
 * Результат индексирован по id блюда: так createOrder() сразу видит,
 * каких блюд в базе уже нет.
 *
 * @param array $cart Корзина вида [id блюда => количество].
 * @return array Массив [id блюда => строка блюда]. Пустой массив для пустой корзины.
 */
function getOrderItemsDetails(array $cart): array
{
    if (empty($cart)) {
        return [];
    }

    $dishIds = array_keys($cart);

    // Подготовленные выражения не принимают массив в IN (), поэтому
    // подставляем столько ?, сколько блюд в корзине.
    $placeholders = implode(', ', array_fill(0, count($dishIds), '?'));

    $stmt = getMysqlConnection()->prepare(
        'SELECT dishes.id, dishes.name, dishes.price, dishes.is_available,
                dishes.venue_id, venues.name AS venue_name
         FROM dishes
         JOIN venues ON venues.id = dishes.venue_id
         WHERE dishes.id IN (' . $placeholders . ')'
    );
    $stmt->execute($dishIds);

    $details = [];

    foreach ($stmt->fetchAll() as $dish) {
        $details[(int) $dish['id']] = $dish;
    }

    return $details;
}

/**
 * Оформляет заказ из корзины и очищает её при успехе.
 *
 * Заказ создаётся одной транзакцией: строка в orders и все строки
 * order_items должны появиться вместе, иначе откат. Цены берутся из dishes
 * и копируются в order_items (снимок на момент заказа), сумма из формы
 * не принимается.
 *
 * Строку слота блокируем через FOR UPDATE, чтобы два студента одновременно
 * не заняли последнее место. Статус заказа не передаём: в схеме у него
 * DEFAULT 'new', а менять статус будет сотрудник.
 *
 * @param int $userId id текущего пользователя.
 * @param int $venueId Выбранное заведение.
 * @param int $slotId Выбранный слот получения.
 * @param array $cart Корзина вида [id блюда => количество].
 * @return array ['success' => bool, 'order_id' => int|null, 'error' => string].
 */
function createOrder(int $userId, int $venueId, int $slotId, array $cart): array
{
    if ($userId <= 0) {
        return ['success' => false, 'order_id' => null, 'error' => 'Требуется вход в систему.'];
    }

    if (empty($cart)) {
        return ['success' => false, 'order_id' => null, 'error' => 'Корзина пуста, добавлять в заказ нечего.'];
    }

    if ($venueId <= 0 || $slotId <= 0) {
        return ['success' => false, 'order_id' => null, 'error' => 'Выберите место и время получения.'];
    }

    $pdo = getMysqlConnection();

    // Заранее описываем, как откатить транзакцию и вернуть ошибку,
    // чтобы не повторять эти три строки в каждой проверке.
    $fail = function (string $message) use ($pdo): array {
        $pdo->rollBack();

        return ['success' => false, 'order_id' => null, 'error' => $message];
    };

    try {
        $pdo->beginTransaction();

        // Заведение проверяем отдельно: слот мог остаться, а заведение
        // администратор уже выключил.
        $stmt = $pdo->prepare('SELECT id FROM venues WHERE id = ? AND is_active = 1');
        $stmt->execute([$venueId]);

        if ($stmt->fetch() === false) {
            return $fail('Выбранное заведение больше не принимает заказы.');
        }

        $stmt = $pdo->prepare(
            'SELECT id, venue_id, capacity, is_active,
                    (slot_date < CURDATE() OR (slot_date = CURDATE() AND start_time <= CURTIME())) AS is_past
             FROM pickup_slots
             WHERE id = ?
             FOR UPDATE'
        );
        $stmt->execute([$slotId]);
        $slot = $stmt->fetch();

        if ($slot === false) {
            return $fail('Выбранный слот получения не найден.');
        }

        if ((int) $slot['is_active'] !== 1) {
            return $fail('Этот слот получения больше недоступен.');
        }

        if ((int) $slot['is_past'] === 1) {
            return $fail('Время этого слота уже прошло. Выберите другое время.');
        }

        if ((int) $slot['venue_id'] !== $venueId) {
            return $fail('Этот слот относится к другому заведению.');
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM orders WHERE slot_id = ? AND status <> \'cancelled\''
        );
        $stmt->execute([$slotId]);
        $taken = (int) $stmt->fetchColumn();

        if ($taken >= (int) $slot['capacity']) {
            return $fail('На это время мест больше нет. Выберите другой слот.');
        }

        $details = getOrderItemsDetails($cart);
        $items = [];
        $totalPrice = 0.0;

        foreach ($cart as $dishId => $quantity) {
            $dishId = (int) $dishId;
            $quantity = (int) $quantity;

            if (!isset($details[$dishId])) {
                return $fail('Блюдо №' . $dishId . ' больше не существует. Удалите его из корзины.');
            }

            $dish = $details[$dishId];

            if ((int) $dish['is_available'] !== 1) {
                return $fail('Блюдо «' . $dish['name'] . '» сейчас недоступно. Удалите его из корзины.');
            }

            if ((int) $dish['venue_id'] !== $venueId) {
                return $fail(
                    'Блюдо «' . $dish['name'] . '» из другого заведения. '
                    . 'Корзина и место получения должны быть в одном заведении.'
                );
            }

            // Количество проверяем ещё раз: в сессии могло оказаться что угодно.
            if ($quantity < 1 || $quantity > CART_MAX_QUANTITY) {
                return $fail('Неверное количество для блюда «' . $dish['name'] . '».');
            }

            $price = (float) $dish['price'];
            $totalPrice += $price * $quantity;

            $items[] = [
                'dish_id' => $dishId,
                'quantity' => $quantity,
                'price' => $price,
            ];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO orders (user_id, venue_id, slot_id, total_price) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $venueId,
            $slotId,
            number_format(round($totalPrice, 2), 2, '.', ''),
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO order_items (order_id, dish_id, quantity, price) VALUES (?, ?, ?, ?)'
        );

        foreach ($items as $item) {
            $stmt->execute([
                $orderId,
                $item['dish_id'],
                $item['quantity'],
                number_format($item['price'], 2, '.', ''),
            ]);
        }

        $pdo->commit();

        // Корзина переехала в order_items, в сессии её держать незачем.
        clearCart();

        return ['success' => true, 'order_id' => $orderId, 'error' => ''];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Текст ошибки БД пользователю не показываем, он только для лога.
        error_log('Ошибка при оформлении заказа: ' . $e->getMessage());

        return [
            'success' => false,
            'order_id' => null,
            'error' => 'Не удалось оформить заказ из-за ошибки базы данных. Попробуйте ещё раз.',
        ];
    }
}
