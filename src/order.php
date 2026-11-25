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
require_once __DIR__ . '/db_mongo.php';
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

/**
 * Возвращает заказ пользователя для редактирования.
 *
 * Редактировать можно только свой заказ со статусом new: после того как
 * сотрудник его подтвердил, менять поздно (П.9). venue_id возвращаем отдельно
 * от названия заведения, чтобы форма редактирования могла искать блюда
 * и слоты именно этого заведения.
 *
 * @param int $userId id текущего пользователя.
 * @param int $orderId id заказа из адреса страницы.
 * @return array|null Заказ (id, venue_id, venue_name, slot_id, status) с полем
 *                     items (dish_id, name, quantity, price) или null,
 *                     если заказа нет, он чужой или уже не new.
 */
function getUserOrderForEdit(int $userId, int $orderId): ?array
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT orders.id, orders.venue_id, orders.slot_id, orders.status,
                venues.name AS venue_name
         FROM orders
         JOIN venues ON venues.id = orders.venue_id
         WHERE orders.user_id = ? AND orders.id = ? AND orders.status = \'new\''
    );
    $stmt->execute([$userId, $orderId]);
    $order = $stmt->fetch();

    if ($order === false) {
        return null;
    }

    $stmt = getMysqlConnection()->prepare(
        'SELECT order_items.dish_id, dishes.name, order_items.quantity, order_items.price
         FROM order_items
         JOIN dishes ON dishes.id = order_items.dish_id
         WHERE order_items.order_id = ?
         ORDER BY order_items.id'
    );
    $stmt->execute([$orderId]);
    $order['items'] = $stmt->fetchAll();

    return $order;
}

/**
 * Возвращает доступные блюда заведения для формы изменения заказа.
 *
 * Отдельная простая функция вместо похода в src/dishes.php (его ведёт
 * Разработчик 2): нужен только список id, название и цена доступных блюд,
 * без админских полей категории.
 *
 * @param int $venueId id заведения.
 * @return array Список блюд: id, name, price.
 */
function getAvailableDishesForVenue(int $venueId): array
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT id, name, price FROM dishes WHERE venue_id = ? AND is_available = 1 ORDER BY name'
    );
    $stmt->execute([$venueId]);

    return $stmt->fetchAll();
}

/**
 * Собирает состав заказа из полей формы изменения заказа в вид корзины.
 *
 * Форма присылает items[dish_id] => количество (0 убирает позицию) и,
 * отдельно, новое блюдо (new_dish_id + new_dish_quantity) — так выпадающий
 * список не превращается в пункт на каждое блюдо заведения с полем
 * количества у каждого. Если новое блюдо уже есть среди items[],
 * количества складываются, а не перезаписываются.
 *
 * @param array $rawItems Значение $_POST['items'] — [id блюда => строка с количеством].
 * @param int $newDishId id блюда из выпадающего списка «добавить блюдо», 0 если не выбрано.
 * @param int $newDishQuantity Количество нового блюда.
 * @return array Корзина вида [id блюда => количество], только положительные значения.
 */
function parseOrderItemsFromRequest(array $rawItems, int $newDishId, int $newDishQuantity): array
{
    $items = [];

    foreach ($rawItems as $dishId => $rawQuantity) {
        $dishId = (int) $dishId;
        $quantity = (int) $rawQuantity;

        if ($dishId > 0 && $quantity > 0) {
            $items[$dishId] = $quantity;
        }
    }

    if ($newDishId > 0 && $newDishQuantity > 0) {
        $items[$newDishId] = ($items[$newDishId] ?? 0) + $newDishQuantity;
    }

    return $items;
}

/**
 * Проверяет состав заказа против заведения: блюда существуют, доступны
 * и относятся к этому заведению, количество от 1 до CART_MAX_QUANTITY.
 *
 * Проверки такие же, как в createOrder(), но вынесены отдельно: createOrder()
 * не трогаем (риск что-то сломать в уже проверенном коде), а для изменения
 * заказа своя точка входа всё равно нужна (другой набор данных на входе —
 * без venue_id/slot_id из формы, они уже определены самим заказом).
 *
 * @param array $cart Состав вида [id блюда => количество].
 * @param int $venueId Заведение, которому должны принадлежать блюда.
 * @return array{items: array, error: string|null} items — список
 *         [dish_id, quantity, price] при успехе, иначе error с причиной отказа.
 */
function validateOrderItems(array $cart, int $venueId): array
{
    $details = getOrderItemsDetails($cart);
    $items = [];

    foreach ($cart as $dishId => $quantity) {
        $dishId = (int) $dishId;
        $quantity = (int) $quantity;

        if (!isset($details[$dishId])) {
            return ['items' => [], 'error' => 'Блюдо №' . $dishId . ' больше не существует.'];
        }

        $dish = $details[$dishId];

        if ((int) $dish['is_available'] !== 1) {
            return ['items' => [], 'error' => 'Блюдо «' . $dish['name'] . '» сейчас недоступно.'];
        }

        if ((int) $dish['venue_id'] !== $venueId) {
            return ['items' => [], 'error' => 'Блюдо «' . $dish['name'] . '» из другого заведения.'];
        }

        if ($quantity < 1 || $quantity > CART_MAX_QUANTITY) {
            return ['items' => [], 'error' => 'Неверное количество для блюда «' . $dish['name'] . '».'];
        }

        $items[] = [
            'dish_id' => $dishId,
            'quantity' => $quantity,
            'price' => (float) $dish['price'],
        ];
    }

    return ['items' => $items, 'error' => null];
}

/**
 * Проверяет слот получения при смене времени уже оформленного заказа.
 *
 * В отличие от проверки слота в createOrder(), свой же заказ не должен
 * мешать себе занять тот же слот: при подсчёте занятых мест текущий заказ
 * исключается (id <> $orderId), иначе смена слота на уже занятый этим же
 * заказом всегда проваливалась бы. По решению заказчика (П.8) заказ можно
 * перенести только на сегодняшний слот.
 *
 * @param int $slotId Новый слот.
 * @param int $venueId Заведение заказа (слот должен быть из него же).
 * @param int $orderId id заказа, который меняют (исключается из подсчёта занятости).
 * @return array{ok: bool, error: string|null}
 */
function isSlotAvailableForUpdate(int $slotId, int $venueId, int $orderId): array
{
    $pdo = getMysqlConnection();

    $stmt = $pdo->prepare(
        'SELECT id, venue_id, capacity, is_active,
                (slot_date <> CURDATE() OR start_time <= CURTIME()) AS is_unavailable_time
         FROM pickup_slots
         WHERE id = ?
         FOR UPDATE'
    );
    $stmt->execute([$slotId]);
    $slot = $stmt->fetch();

    if ($slot === false) {
        return ['ok' => false, 'error' => 'Выбранный слот получения не найден.'];
    }

    if ((int) $slot['is_active'] !== 1) {
        return ['ok' => false, 'error' => 'Этот слот получения больше недоступен.'];
    }

    if ((int) $slot['is_unavailable_time'] === 1) {
        return ['ok' => false, 'error' => 'Это время получения уже недоступно. Выберите слот на сегодня.'];
    }

    if ((int) $slot['venue_id'] !== $venueId) {
        return ['ok' => false, 'error' => 'Этот слот относится к другому заведению.'];
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders WHERE slot_id = ? AND status <> 'cancelled' AND id <> ?"
    );
    $stmt->execute([$slotId, $orderId]);
    $taken = (int) $stmt->fetchColumn();

    if ($taken >= (int) $slot['capacity']) {
        return ['ok' => false, 'error' => 'На это время мест больше нет. Выберите другой слот.'];
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Отменяет заказ студентом, пока сотрудник его не подтвердил (П.9).
 *
 * FOR UPDATE по строке заказа защищает от гонки с сотрудником: если он
 * в этот момент меняет статус, одна из двух транзакций подождёт другую,
 * а не обе одновременно прочитают старый status. Отмена освобождает место
 * в слоте: подсчёт занятости везде смотрит на status <> 'cancelled'.
 *
 * Запись в Mongo — после коммита в MySQL, по тому же правилу, что
 * у changeOrderStatus() в src/staff_orders.php: если Mongo недоступна,
 * статус в MySQL не откатываем, только предупреждаем пользователя.
 *
 * @param int $userId id текущего пользователя.
 * @param int $orderId id заказа.
 * @return array{ok: bool, error: string|null, historyWarning: bool}
 */
function cancelOrderByStudent(int $userId, int $orderId): array
{
    $pdo = getMysqlConnection();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ? AND user_id = ? FOR UPDATE');
    $stmt->execute([$orderId, $userId]);
    $currentStatus = $stmt->fetchColumn();

    if ($currentStatus === false) {
        $pdo->rollBack();

        return ['ok' => false, 'error' => 'Заказ не найден.', 'historyWarning' => false];
    }

    if ($currentStatus !== 'new') {
        $pdo->rollBack();

        return [
            'ok' => false,
            'error' => 'Заказ уже подтверждён сотрудником, отменить нельзя.',
            'historyWarning' => false,
        ];
    }

    $updateStmt = $pdo->prepare(
        "UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND user_id = ?"
    );
    $updateStmt->execute([$orderId, $userId]);
    $pdo->commit();

    $historyWarning = false;

    try {
        getMongoDatabase()->selectCollection('order_status_history')->insertOne([
            'order_id' => $orderId,
            'old_status' => $currentStatus,
            'new_status' => 'cancelled',
            'changed_by' => $userId,
            'changed_at' => new MongoDB\BSON\UTCDateTime(),
        ]);
    } catch (Throwable $e) {
        error_log('Не удалось записать отмену заказа в MongoDB: ' . $e->getMessage());
        $historyWarning = true;
    }

    return ['ok' => true, 'error' => null, 'historyWarning' => $historyWarning];
}

/**
 * Меняет слот и/или состав уже оформленного заказа, пока он в статусе new (П.9).
 *
 * Одна транзакция на всё: FOR UPDATE по заказу — от гонки с сотрудником,
 * FOR UPDATE по слоту (внутри isSlotAvailableForUpdate()) — от гонки
 * с другим студентом за последнее место. Старые order_items удаляются
 * и вставляются заново — проще, чем вычислять разницу, и гарантирует,
 * что не останется позиций, убранных студентом.
 *
 * Перед записью сравниваем старый и новый слот/состав, чтобы записать
 * в Mongo правильный action (contract 2.3): slot_changed, items_changed
 * или order_changed. Если не изменилось вообще ничего — считаем успехом,
 * но в Mongo не пишем: это не событие, о котором стоит хранить историю.
 *
 * @param int $userId id текущего пользователя.
 * @param int $orderId id заказа.
 * @param int $slotId Новый слот получения.
 * @param array $items Новый состав вида [id блюда => количество].
 * @return array{ok: bool, error: string|null, historyWarning: bool, action: string|null}
 */
function updateOrderByStudent(int $userId, int $orderId, int $slotId, array $items): array
{
    if ($slotId <= 0) {
        return ['ok' => false, 'error' => 'Выберите время получения.', 'historyWarning' => false, 'action' => null];
    }

    if (empty($items)) {
        return [
            'ok' => false,
            'error' => 'В заказе должно остаться хотя бы одно блюдо.',
            'historyWarning' => false,
            'action' => null,
        ];
    }

    $pdo = getMysqlConnection();
    $pdo->beginTransaction();

    $fail = function (string $message) use ($pdo): array {
        $pdo->rollBack();

        return ['ok' => false, 'error' => $message, 'historyWarning' => false, 'action' => null];
    };

    $stmt = $pdo->prepare('SELECT venue_id, slot_id, status FROM orders WHERE id = ? AND user_id = ? FOR UPDATE');
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();

    if ($order === false) {
        return $fail('Заказ не найден.');
    }

    if ($order['status'] !== 'new') {
        return $fail('Заказ уже подтверждён сотрудником, изменить нельзя.');
    }

    $venueId = (int) $order['venue_id'];
    $oldSlotId = (int) $order['slot_id'];

    $slotCheck = isSlotAvailableForUpdate($slotId, $venueId, $orderId);

    if (!$slotCheck['ok']) {
        return $fail($slotCheck['error']);
    }

    $validated = validateOrderItems($items, $venueId);

    if ($validated['error'] !== null) {
        return $fail($validated['error']);
    }

    $newItems = $validated['items'];

    // Старый состав нужен только для сравнения с новым (какой action писать
    // в Mongo), поэтому читаем его до удаления строк.
    $stmt = $pdo->prepare('SELECT dish_id, quantity FROM order_items WHERE order_id = ?');
    $stmt->execute([$orderId]);
    $oldItemsMap = [];

    foreach ($stmt->fetchAll() as $row) {
        $oldItemsMap[(int) $row['dish_id']] = (int) $row['quantity'];
    }

    $newItemsMap = [];
    $totalPrice = 0.0;

    foreach ($newItems as $item) {
        $newItemsMap[$item['dish_id']] = $item['quantity'];
        $totalPrice += $item['price'] * $item['quantity'];
    }

    ksort($oldItemsMap);
    ksort($newItemsMap);

    $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);

    $insertStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, dish_id, quantity, price) VALUES (?, ?, ?, ?)'
    );

    foreach ($newItems as $item) {
        $insertStmt->execute([
            $orderId,
            $item['dish_id'],
            $item['quantity'],
            number_format($item['price'], 2, '.', ''),
        ]);
    }

    $pdo->prepare(
        'UPDATE orders SET slot_id = ?, total_price = ?, updated_at = NOW() WHERE id = ? AND user_id = ?'
    )->execute([
        $slotId,
        number_format(round($totalPrice, 2), 2, '.', ''),
        $orderId,
        $userId,
    ]);

    $pdo->commit();

    $slotChanged = $slotId !== $oldSlotId;
    $itemsChanged = $oldItemsMap !== $newItemsMap;

    if ($slotChanged && $itemsChanged) {
        $action = 'order_changed';
    } elseif ($slotChanged) {
        $action = 'slot_changed';
    } elseif ($itemsChanged) {
        $action = 'items_changed';
    } else {
        $action = null;
    }

    $historyWarning = false;

    if ($action !== null) {
        try {
            getMongoDatabase()->selectCollection('order_status_history')->insertOne([
                'order_id' => $orderId,
                'old_status' => 'new',
                'new_status' => 'new',
                'action' => $action,
                'changed_by' => $userId,
                'changed_at' => new MongoDB\BSON\UTCDateTime(),
            ]);
        } catch (Throwable $e) {
            error_log('Не удалось записать изменение заказа в MongoDB: ' . $e->getMessage());
            $historyWarning = true;
        }
    }

    return ['ok' => true, 'error' => null, 'historyWarning' => $historyWarning, 'action' => $action];
}
