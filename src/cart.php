<?php
/**
 * Корзина студента.
 *
 * Корзина хранится в сессии: до оформления заказа она не обязана попадать
 * в базу, а таблицы dishes и carts пока не созданы. В корзине лежит только
 * id блюда и количество, поэтому названия и цены здесь не хранятся.
 *
 * Структура: $_SESSION['cart'] = [id блюда => количество].
 */

require_once __DIR__ . '/session.php';

/** Максимальное количество одной позиции в корзине. */
const CART_MAX_QUANTITY = 99;

/**
 * Возвращает содержимое корзины.
 *
 * @return array Массив вида [id блюда => количество]. Пустой массив, если корзина пуста.
 */
function getCart(): array
{
    startSession();

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        return [];
    }

    return $_SESSION['cart'];
}

/**
 * Считает общее количество порций в корзине.
 *
 * Нужно для будущей шапки сайта, где показывается счётчик рядом со ссылкой
 * на корзину.
 *
 * @return int Сумма количеств всех позиций.
 */
function getCartItemCount(): int
{
    return array_sum(getCart());
}

/**
 * Добавляет блюдо в корзину.
 *
 * Если блюдо уже есть, количество увеличивается. Слишком большое количество
 * ограничиваем, чтобы в корзине не оказалось 100500 порций.
 *
 * @param int $dishId Id блюда.
 * @param int $quantity Сколько порций добавить.
 * @return bool true, если данные корректны и блюдо добавлено.
 */
function addToCart(int $dishId, int $quantity = 1): bool
{
    if ($dishId <= 0 || $quantity <= 0) {
        return false;
    }

    $cart = getCart();
    $newQuantity = ($cart[$dishId] ?? 0) + $quantity;

    if ($newQuantity > CART_MAX_QUANTITY) {
        $newQuantity = CART_MAX_QUANTITY;
    }

    $cart[$dishId] = $newQuantity;
    $_SESSION['cart'] = $cart;

    return true;
}

/**
 * Меняет количество одной позиции.
 *
 * Если новое количество меньше или равно нулю, позиция удаляется: так
 * пользователю не нужно нажимать отдельную кнопку «Удалить», когда он
 * выставил ноль.
 *
 * @param int $dishId Id блюда.
 * @param int $quantity Новое количество порций.
 * @return bool true, если данные корректны и изменение применено.
 */
function updateCartQuantity(int $dishId, int $quantity): bool
{
    if ($dishId <= 0) {
        return false;
    }

    if ($quantity <= 0) {
        removeFromCart($dishId);
        return true;
    }

    if ($quantity > CART_MAX_QUANTITY) {
        $quantity = CART_MAX_QUANTITY;
    }

    $cart = getCart();
    $cart[$dishId] = $quantity;
    $_SESSION['cart'] = $cart;

    return true;
}

/**
 * Удаляет блюдо из корзины.
 *
 * @param int $dishId Id блюда.
 * @return bool true, если id корректный.
 */
function removeFromCart(int $dishId): bool
{
    if ($dishId <= 0) {
        return false;
    }

    $cart = getCart();
    unset($cart[$dishId]);
    $_SESSION['cart'] = $cart;

    return true;
}

/**
 * Полностью очищает корзину.
 *
 * Понадобится после оформления заказа, когда позиции перейдут в таблицу
 * заказа и в сессии их держать незачем.
 *
 * @return void
 */
function clearCart(): void
{
    startSession();

    unset($_SESSION['cart']);
}

/**
 * Проверяет, что запрос пришёл через JavaScript (site.js), а не обычной
 * отправкой формы.
 *
 * Нужно, чтобы на добавление в корзину без ajax отвечать редиректом
 * (как раньше), а с ajax — JSON без перезагрузки страницы.
 *
 * @return bool true, если в POST пришёл признак ajax=1.
 */
function isAjaxRequest(): bool
{
    return ($_POST['ajax'] ?? null) === '1';
}

/**
 * Проверяет адрес для возврата после добавления в корзину.
 *
 * Адрес приходит из поля return_to на странице, а значит его можно
 * подделать. Разрешаем только локальный путь: один ведущий слеш, без
 * протокола и хоста (иначе получится открытый редирект на чужой сайт),
 * без обратного слеша и переводов строк (защита от подмены заголовка
 * Location). Если путь не прошёл проверку, возвращаем запасной адрес.
 *
 * @param string|null $path Значение поля return_to из формы.
 * @param string $fallback Запасной адрес, если $path не подошёл.
 * @return string Безопасный локальный путь.
 */
function getSafeReturnPath(?string $path, string $fallback = '/'): string
{
    if ($path === null || $path === '' || strlen($path) > 200) {
        return $fallback;
    }

    if ($path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')) {
        return $fallback;
    }

    if (str_contains($path, "\n") || str_contains($path, "\r")) {
        return $fallback;
    }

    if (!preg_match('/^[A-Za-z0-9\-_.~\/?=&%#]+$/', $path)) {
        return $fallback;
    }

    // parse_url() не должен находить host или scheme — иначе это ссылка
    // на другой сайт, а не локальный путь.
    $parts = parse_url($path);
    if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) {
        return $fallback;
    }

    return $path;
}

/**
 * Отправляет JSON-ответ на ajax-добавление в корзину и завершает скрипт.
 *
 * @param bool $ok Успешно ли выполнено действие.
 * @param string $message Текст для пользователя (уведомление в site.js).
 * @param int $status HTTP-код ответа.
 * @return void
 */
function sendAddToCartJson(bool $ok, string $message, int $status = 200): void
{
    header('Content-Type: application/json');
    http_response_code($status);

    $response = [
        'ok' => $ok,
        'message' => $message,
    ];

    if ($ok) {
        $response['cartCount'] = getCartItemCount();
    }

    if ($status === 401) {
        $response['loginUrl'] = '/login.php';
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}
