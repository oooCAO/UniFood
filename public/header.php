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
$currentRole = getCurrentUserRole();

// Путь текущей страницы нужен, чтобы подсветить активный раздел в меню.
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
?>
<header class="site-header">
    <div class="container header-inner">
        <a class="logo" href="/">UniFood</a>

        <nav class="main-nav">
            <a href="/" class="<?= $currentPath === '/' ? 'active' : '' ?>">Заведения</a>
            <a class="cart-link<?= $currentPath === '/cart.php' ? ' active' : '' ?>" href="/cart.php">Корзина
                <span class="cart-count" data-cart-count<?= $cartCount > 0 ? '' : ' hidden' ?>><?= (int) $cartCount ?></span>
            </a>

            <?php if (isLoggedIn()): ?>
                <?php if ($currentRole === ROLE_STUDENT): ?>
                    <a href="/orders.php" class="<?= $currentPath === '/orders.php' ? 'active' : '' ?>">Мои заказы</a>
                <?php elseif ($currentRole === ROLE_STAFF): ?>
                    <a href="/staff/orders.php" class="<?= strpos($currentPath, '/staff/') === 0 ? 'active' : '' ?>">Заказы</a>
                <?php elseif ($currentRole === ROLE_ADMIN): ?>
                    <a href="/admin/venues.php" class="<?= strpos($currentPath, '/admin/') === 0 ? 'active' : '' ?>">Админка</a>
                <?php endif; ?>

                <a href="/logout.php">Выйти</a>
            <?php else: ?>
                <a href="/login.php" class="<?= $currentPath === '/login.php' ? 'active' : '' ?>">Вход</a>
                <a href="/register.php" class="<?= $currentPath === '/register.php' ? 'active' : '' ?>">Регистрация</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
