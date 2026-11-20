<?php
/**
 * Подключение к MySQL через PDO.
 *
 * Используется во всех местах, где нужно работать с реляционной базой:
 * пользователи, заведения, блюда, заказы.
 */

require_once __DIR__ . '/config.php';

/**
 * Возвращает подключение PDO к MySQL.
 *
 * Подключение создаётся один раз на запрос: повторные вызовы возвращают
 * то же самое соединение, чтобы не открывать лишние коннекты.
 *
 * @return PDO Подключение к базе MySQL.
 * @throws PDOException Если подключиться не удалось.
 */
function getMysqlConnection(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $config = getConfig()['mysql'];

    // charset=utf8mb4 нужен для русских букв и эмодзи в названиях блюд.
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['name']
    );

    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        // Ошибки SQL должны быть исключениями, а не «тихими» false:
        // так их не пропустишь случайно.
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        // Ассоциативные массивы удобнее читать в коде страниц.
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
