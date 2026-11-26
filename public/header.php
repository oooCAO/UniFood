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
        <a class="logo" href="/">
            <span class="logo-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 11h16a8 8 0 0 1-16 0Z"></path>
                    <path d="M3 20h18"></path>
                    <path d="M9 7c0-1.2.6-2 1.5-2.8"></path>
                    <path d="M13 7c0-1.2.6-2 1.5-2.8"></path>
                </svg>
            </span>
            <span class="logo-text">UniFood</span>
        </a>

        <!-- Кнопку показывает site.js. Без JS меню остаётся видимым. -->
        <button class="nav-toggle" type="button" aria-label="Открыть меню"
                aria-expanded="false" aria-controls="main-nav" hidden>
            <span class="nav-toggle-line"></span>
            <span class="nav-toggle-line"></span>
            <span class="nav-toggle-line"></span>
        </button>

        <nav class="main-nav" id="main-nav" aria-label="Основная навигация">
            <a href="/" class="<?= $currentPath === '/' ? 'active' : '' ?>">Заведения</a>

            <?php
            // Счётчик рендерится всегда, при нуле он скрыт: так его можно
            // обновлять без пересоздания ссылки (договорённость C4).
            ?>
            <a class="cart-link <?= $currentPath === '/cart.php' ? 'active' : '' ?>" href="/cart.php">Корзина
                <span class="cart-count" data-cart-count<?= $cartCount > 0 ? '' : ' hidden' ?>><?= (int) $cartCount ?></span>
            </a>

            <?php if (isLoggedIn()): ?>
                <?php if ($currentRole === ROLE_STUDENT): ?>
                    <a class="nav-role <?= $currentPath === '/orders.php' ? 'active' : '' ?>" href="/orders.php">Мои заказы</a>
                <?php elseif ($currentRole === ROLE_STAFF): ?>
                    <a class="nav-role <?= strpos($currentPath, '/staff/') === 0 ? 'active' : '' ?>" href="/staff/orders.php">Заказы</a>
                <?php elseif ($currentRole === ROLE_ADMIN): ?>
                    <a class="nav-role <?= strpos($currentPath, '/admin/') === 0 ? 'active' : '' ?>" href="/admin/venues.php">Админка</a>
                <?php endif; ?>

                <a class="nav-logout" href="/logout.php">Выйти</a>
            <?php else: ?>
                <a class="nav-login <?= $currentPath === '/login.php' ? 'active' : '' ?>" href="/login.php">Вход</a>
                <a class="nav-register <?= $currentPath === '/register.php' ? 'active' : '' ?>" href="/register.php">Регистрация</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
