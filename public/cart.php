<?php
/**
 * Страница корзины.
 *
 * Показывает позиции из сессии и обрабатывает три действия: добавить блюдо,
 * изменить количество и удалить. Добавлять блюда сюда будут кнопки на
 * страницах каталога — они отправляют POST с action=add.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/cart.php';

// Корзина есть только у вошедшего пользователя, гостя отправляем на вход.
requireLogin();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
    } else {
        $action = $_POST['action'] ?? '';
        $dishId = (int) ($_POST['dish_id'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 1);

        switch ($action) {
            case 'add':
                // Добавляем по одной порции: кнопка «В корзину» всегда шлёт 1.
                if (addToCart($dishId, 1)) {
                    header('Location: /cart.php');
                    exit;
                }
                $error = 'Не удалось добавить блюдо: неверный id.';
                break;

            case 'update':
                if (updateCartQuantity($dishId, $quantity)) {
                    header('Location: /cart.php');
                    exit;
                }
                $error = 'Не удалось изменить количество: неверный id.';
                break;

            case 'remove':
                if (removeFromCart($dishId)) {
                    header('Location: /cart.php');
                    exit;
                }
                $error = 'Не удалось удалить блюдо: неверный id.';
                break;

            default:
                $error = 'Неизвестное действие.';
        }
    }
}

$cart = getCart();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Корзина — UniFood</title>
</head>
<body>
<h1>Корзина</h1>

<?php if ($error !== ''): ?>
    <p><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if (empty($cart)): ?>
    <p>Корзина пуста.</p>
<?php else: ?>
    <p>Всего порций: <?= getCartItemCount() ?></p>

    <table>
        <tr>
            <th>Блюдо</th>
            <th>Количество</th>
            <th>Действия</th>
        </tr>
        <?php foreach ($cart as $dishId => $quantity): ?>
            <tr>
                <!-- Названия и цены появятся, когда Разработчик 2 добавит таблицу dishes. -->
                <td>Блюдо №<?= (int) $dishId ?></td>
                <td>
                    <form method="post" action="/cart.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="dish_id" value="<?= (int) $dishId ?>">
                        <input type="number" name="quantity" min="1" max="<?= CART_MAX_QUANTITY ?>" value="<?= (int) $quantity ?>" required>
                        <button type="submit">Изменить</button>
                    </form>
                </td>
                <td>
                    <form method="post" action="/cart.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="dish_id" value="<?= (int) $dishId ?>">
                        <button type="submit">Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <p><a href="/order.php">Оформить заказ</a></p>

    <p>Названия и цены блюд появятся после подключения таблицы dishes.</p>
<?php endif; ?>

<p><a href="/">На главную</a></p>
</body>
</html>
