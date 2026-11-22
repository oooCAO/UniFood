<?php
/**
 * Управление пользователями (админка).
 *
 * Обычная регистрация (register.php) создаёт только студентов. Здесь —
 * создание аккаунтов с любой ролью и смена роли/заведения существующим.
 * Удаления нет: на users.id ссылаются orders.user_id.
 */

require_once __DIR__ . '/db_mysql.php';
require_once __DIR__ . '/roles.php';

/**
 * Возвращает всех пользователей.
 *
 * @return array Список строк users: id, email, full_name, role, venue_id,
 *               venue_name (название заведения сотрудника или null), created_at.
 */
function listUsers(): array
{
    $sql = 'SELECT users.id, users.email, users.full_name, users.role,
                   users.venue_id, venues.name AS venue_name, users.created_at
            FROM users
            LEFT JOIN venues ON venues.id = users.venue_id
            ORDER BY users.id';

    return getMysqlConnection()->query($sql)->fetchAll();
}

/**
 * Создаёт пользователя с произвольной ролью.
 *
 * venue_id имеет смысл только для роли staff: для student и admin
 * он всегда записывается как NULL, даже если передан.
 *
 * @param string $email Email пользователя.
 * @param string $fullName Имя пользователя.
 * @param string $password Пароль в открытом виде.
 * @param string $role Роль: ROLE_STUDENT, ROLE_STAFF или ROLE_ADMIN.
 * @param int|null $venueId Заведение сотрудника (игнорируется, если роль не staff).
 * @return int|null Id созданного пользователя или null, если email уже занят.
 */
function createUser(string $email, string $fullName, string $password, string $role, ?int $venueId): ?int
{
    $email = mb_strtolower(trim($email));
    $venueId = $role === ROLE_STAFF ? $venueId : null;

    $stmt = getMysqlConnection()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);

    if ($stmt->fetch() !== false) {
        return null;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $insert = getMysqlConnection()->prepare(
        'INSERT INTO users (email, password_hash, full_name, role, venue_id) VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute([$email, $passwordHash, trim($fullName), $role, $venueId]);

    return (int) getMysqlConnection()->lastInsertId();
}

/**
 * Меняет роль и заведение пользователя.
 *
 * @param int $id Id пользователя.
 * @param string $role Новая роль.
 * @param int|null $venueId Заведение сотрудника (игнорируется, если роль не staff).
 * @return bool true, если пользователь с таким id найден и обновлён.
 */
function updateUserRole(int $id, string $role, ?int $venueId): bool
{
    $venueId = $role === ROLE_STAFF ? $venueId : null;

    $stmt = getMysqlConnection()->prepare('UPDATE users SET role = ?, venue_id = ? WHERE id = ?');
    $stmt->execute([$role, $venueId, $id]);

    return $stmt->rowCount() > 0;
}
