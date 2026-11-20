<?php
/**
 * Настройки приложения.
 *
 * Все значения берутся из переменных окружения контейнера (они задаются
 * в docker-compose.yml из файла .env). Паролей в коде нет: если .env
 * не заполнен, подключение к базам просто не заработает.
 */

/**
 * Возвращает настройки подключения к MySQL и MongoDB одним массивом.
 *
 * Функция запоминает результат в статической переменной, чтобы при нескольких
 * вызовах за запрос не читать окружение заново.
 *
 * @return array{
 *     mysql: array{host: string, port: int, name: string, user: string, password: string},
 *     mongo: array{host: string, port: int, name: string, user: string, password: string}
 * }
 */
function getConfig(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = [
        'mysql' => [
            'host' => getenv('DB_HOST') ?: 'mysql',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'name' => getenv('DB_NAME') ?: '',
            'user' => getenv('DB_USER') ?: '',
            'password' => getenv('DB_PASSWORD') ?: '',
        ],
        'mongo' => [
            'host' => getenv('MONGO_HOST') ?: 'mongo',
            'port' => (int) (getenv('MONGO_DB_PORT') ?: 27017),
            'name' => getenv('MONGO_DB_NAME') ?: '',
            'user' => getenv('MONGO_USER') ?: '',
            'password' => getenv('MONGO_PASSWORD') ?: '',
        ],
    ];

    return $config;
}
