<?php
/**
 * Регистрация, вход и выход пользователя.
 *
 * Здесь собраны функции, которые нужны страницам register.php, login.php
 * и logout.php: работа с CSRF-токеном, поиск пользователя, создание записи
 * и запись данных в сессию. Сами страницы только показывают формы
 * и вызывают эти функции.
 */

require_once __DIR__ . '/db_mysql.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/roles.php';

/**
 * Возвращает CSRF-токен для текущей сессии.
 *
 * Токен создаётся один раз и хранится в сессии, поэтому все формы одного
 * пользователя отправляют одно и то же значение. Случайная строка нужна,
 * чтобы чужой сайт не мог подделать отправку формы от имени пользователя.
 *
 * @return string Токен в виде hex-строки.
 */
function getCsrfToken(): string
{
    startSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Проверяет CSRF-токен, пришедший из формы.
 *
 * Сравнение делаем через hash_equals(), а не через ===, чтобы время
 * сравнения не зависело от того, сколько символов совпало.
 *
 * @param string|null $token Значение поля csrf_token из формы.
 * @return bool true, если токен совпадает с сохранённым в сессии.
 */
function checkCsrfToken(?string $token): bool
{
    startSession();

    if (empty($_SESSION['csrf_token']) || $token === null || $token === '') {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Ищет пользователя по email.
 *
 * Email по контракту хранится в нижнем регистре, поэтому вход и регистрация
 * всегда приводят его к нижнему регистру перед сравнением.
 *
 * @param string $email Email пользователя.
 * @return array|null Строка пользователя или null, если такого email нет.
 */
function findUserByEmail(string $email): ?array
{
    $email = mb_strtolower(trim($email));

    $stmt = getMysqlConnection()->prepare(
        'SELECT id, email, password_hash, full_name, role FROM users WHERE email = ?'
    );
    $stmt->execute([$email]);

    $user = $stmt->fetch();

    return $user === false ? null : $user;
}

/**
 * Создаёт нового пользователя с ролью student.
 *
 * Пароль хранится только в виде хеша: password_hash() сам выбирает
 * актуальный алгоритм и соль.
 *
 * @param string $email Email пользователя (уже проверенный).
 * @param string $fullName Имя пользователя.
 * @param string $password Пароль в открытом виде.
 * @return int id созданного пользователя.
 */
function registerUser(string $email, string $fullName, string $password): int
{
    $email = mb_strtolower(trim($email));
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Роль не передаём: в таблице users для неё задано DEFAULT 'student'.
    $stmt = getMysqlConnection()->prepare(
        'INSERT INTO users (email, password_hash, full_name) VALUES (?, ?, ?)'
    );
    $stmt->execute([$email, $passwordHash, trim($fullName)]);

    return (int) getMysqlConnection()->lastInsertId();
}

/**
 * Записывает пользователя в сессию, то есть выполняет вход.
 *
 * Идентификатор сессии меняем после входа: если он был украден до
 * авторизации, старый cookie уже бесполезен (защита от фиксации сессии).
 *
 * @param array $user Строка пользователя из findUserByEmail().
 * @return void
 */
function loginUser(array $user): void
{
    startSession();

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = (string) $user['role'];
}

/**
 * Завершает сессию пользователя.
 *
 * Удаляем и данные, и cookie сессии, иначе браузер продолжит присылать
 * старый идентификатор.
 *
 * @return void
 */
function logoutUser(): void
{
    startSession();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }

    session_destroy();
}
