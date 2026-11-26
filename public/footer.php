<?php
/**
 * Общий подвал сайта.
 *
 * Подключается страницами каталога после основного содержимого.
 * Здесь же подключается site.js: скрипт отвечает только за оформление
 * и не обязателен для работы страниц.
 */
?>
<footer class="site-footer">
    <div class="container footer-inner">
        <div class="footer-brand">
            <span class="footer-logo">UniFood</span>
            <p>Предзаказ еды в столовых и кафе ГрГУ.</p>
        </div>
        <p class="footer-note">© <?= date('Y') ?> UniFood · ГрГУ</p>
    </div>
</footer>

<!-- Контейнер уведомлений site.js (контракт C5): один на страницу. -->
<div id="site-toast" class="site-toast" role="status" aria-live="polite"></div>

<script src="/js/site.js" defer></script>
