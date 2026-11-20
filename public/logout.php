<?php
/**
 * Страница выхода.
 *
 * Стирает данные сессии и возвращает пользователя на главную страницу.
 */

require_once __DIR__ . '/../src/auth.php';

logoutUser();

header('Location: /');
exit;
