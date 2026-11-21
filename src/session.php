<?php
/**
 * Работа с сессией пользователя.
 *
 * Общий файл для всех страниц: и для входа, и для проверки роли.
 */

/**
 * Запускает сессию, если она ещё не запущена.
 *
 * Функцию можно вызывать несколько раз за запрос — повторный вызов
 * ничего не сломает и не выдаст предупреждение.
 *
 * @return void
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Если страница уже что-то вывела, менять настройки cookie поздно:
    // PHP выдаст предупреждение. В таком случае просто стартуем сессию.
    if (headers_sent()) {
        session_start();
        return;
    }

    // httponly запрещает читать cookie из JavaScript (защита от кражи сессии),
    // samesite не даёт отправлять её с чужих сайтов (защита от CSRF).
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
