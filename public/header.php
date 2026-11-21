<?php
/**
 * Общая шапка сайта.
 *
 * Подключается страницами каталога, чтобы навигация и счётчик корзины
 * выглядели одинаково на всех страницах.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/cart.php';

// Счётчик нужен рядом со ссылкой на корзину, он считает порции из сессии.
$cartCount = getCartItemCount();
?>
<header class="site-header">
    <div class="container header-inner">
        <a class="logo" href="/">UniFood</a>

        <nav class="main-nav">
            <a href="/">Заведения</a>
            <a href="/menu.php">Меню</a>
            <a href="/cart.php">Корзина <span class="cart-count"><?= (int) $cartCount ?></span></a>

            <?php if (isLoggedIn()): ?>
                <a href="/logout.php">Выйти</a>
            <?php else: ?>
                <a href="/login.php">Вход</a>
                <a href="/register.php">Регистрация</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
