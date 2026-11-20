<?php
/**
 * Роли пользователей и проверки доступа.
 *
 * Логики входа и регистрации здесь нет — она в другой ветке. Этот файл
 * только хранит названия ролей и проверяет, что пользователь вошёл
 * и имеет нужную роль.
 */

require_once __DIR__ . '/session.php';

/** Роль студента (обычный заказчик). */
const ROLE_STUDENT = 'student';

/** Роль сотрудника столовой или кафе. */
const ROLE_STAFF = 'staff';

/** Роль администратора. */
const ROLE_ADMIN = 'admin';

/**
 * Проверяет, вошёл ли пользователь.
 *
 * Признак входа — id пользователя в сессии, его записывает страница входа.
 *
 * @return bool true, если пользователь авторизован.
 */
function isLoggedIn(): bool
{
    startSession();

    return !empty($_SESSION['user_id']);
}

/**
 * Возвращает роль текущего пользователя.
 *
 * @return string|null Название роли (student, staff, admin) или null, если гость.
 */
function getCurrentUserRole(): ?string
{
    startSession();

    return $_SESSION['role'] ?? null;
}

/**
 * Пускает дальше только авторизованных пользователей.
 *
 * Гостю отвечаем кодом 401 и завершаем скрипт, чтобы страница
 * не успела вывести данные.
 *
 * @return void
 */
function requireLogin(): void
{
    if (isLoggedIn()) {
        return;
    }

    http_response_code(401);
    exit('Требуется вход в систему.');
}

/**
 * Пускает дальше только пользователей с нужной ролью.
 *
 * Роль берётся из сессии, поэтому страницы входа и смены роли должны
 * записывать туда значение одной из констант ROLE_*.
 *
 * @param string $role Ожидаемая роль: ROLE_STUDENT, ROLE_STAFF или ROLE_ADMIN.
 * @return void
 */
function requireRole(string $role): void
{
    requireLogin();

    if (getCurrentUserRole() === $role) {
        return;
    }

    http_response_code(403);
    exit('Недостаточно прав для этой страницы.');
}
