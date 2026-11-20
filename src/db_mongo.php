<?php
/**
 * Подключение к MongoDB.
 *
 * Используется для нереляционных данных проекта (например, отзывов).
 * Работа идёт через официальную библиотеку mongodb/mongodb.
 */

require_once __DIR__ . '/config.php';

/**
 * Возвращает базу MongoDB для работы с коллекциями.
 *
 * Клиент создаётся один раз на запрос и переиспользуется: так тратится
 * меньше ресурсов, чем при новом подключении на каждый вызов.
 *
 * @return MongoDB\Database База данных приложения.
 * @throws MongoDB\Driver\Exception\ConnectionException Если подключиться не удалось.
 */
function getMongoDatabase(): MongoDB\Database
{
    static $database = null;

    if ($database !== null) {
        return $database;
    }

    $config = getConfig()['mongo'];

    // Логин и пароль могут содержать символы вроде @ или #, поэтому их
    // обязательно кодируем, иначе URI будет разобран неправильно.
    $user = rawurlencode($config['user']);
    $password = rawurlencode($config['password']);

    // root-пользователя образ MongoDB создаёт в базе admin, поэтому
    // указываем authSource=admin, иначе авторизация не пройдёт.
    $uri = sprintf(
        'mongodb://%s:%s@%s:%d/?authSource=admin',
        $user,
        $password,
        $config['host'],
        $config['port']
    );

    $client = new MongoDB\Client($uri);
    $database = $client->selectDatabase($config['name']);

    return $database;
}
