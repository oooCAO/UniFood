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
