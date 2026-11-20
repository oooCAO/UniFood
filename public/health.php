<?php
/**
 * Служебная страница проверки подключений.
 *
 * Показывает, получается ли подключиться к MySQL и MongoDB.
 * Файл временный: перед релизом его нужно удалить.
 */

require_once __DIR__ . '/../src/db_mysql.php';
require_once __DIR__ . '/../src/db_mongo.php';

header('Content-Type: text/plain; charset=utf-8');

// Проверяем MySQL простым запросом: одного создания PDO недостаточно,
// ошибка авторизации проявится только при обращении к серверу.
try {
    getMysqlConnection()->query('SELECT 1');
    echo "MySQL: OK\n";
} catch (Throwable $error) {
    echo 'MySQL: ошибка — ' . $error->getMessage() . "\n";
}

// В MongoDB нет команды SELECT, поэтому проверяем соединение командой ping.
try {
    getMongoDatabase()->command(['ping' => 1])->toArray();
    echo "MongoDB: OK\n";
} catch (Throwable $error) {
    echo 'MongoDB: ошибка — ' . $error->getMessage() . "\n";
}
