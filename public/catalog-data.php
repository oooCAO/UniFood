<?php
/**
 * Данные каталога из базы.
 *
 * Страницы каталога получают здесь списки заведений, категорий и доступных
 * блюд. Запросы простые и без параметров, поэтому подготовленные выражения
 * используются только для однообразия с остальным кодом проекта.
 * Если база недоступна, страницы покажут понятное сообщение, а не ошибку PHP.
 */

require_once __DIR__ . '/../src/db_mysql.php';

$venues = [];
$categories = [];
$dishes = [];
$catalogError = '';

try {
    $connection = getMysqlConnection();

    // Неактивные заведения студенту не показываем.
    $stmt = $connection->prepare('SELECT id, name, address FROM venues WHERE is_active = 1 ORDER BY id');
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $venues[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'address' => (string) $row['address'],
        ];
    }

    $stmt = $connection->prepare(
        'SELECT c.id, c.venue_id, c.name
         FROM categories c
         JOIN venues v ON v.id = c.venue_id
         WHERE v.is_active = 1
         ORDER BY c.venue_id, c.sort_order, c.id'
    );
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $categories[] = [
            'id' => (int) $row['id'],
            'venue_id' => (int) $row['venue_id'],
            'name' => (string) $row['name'],
        ];
    }

    // Недоступные блюда в каталоге не показываем: их нельзя заказать.
    // Заведения тоже проверяем: после отключения столовой её блюда
    // не должны оставаться в меню и на странице блюда.
    $stmt = $connection->prepare(
        'SELECT d.id, d.venue_id, d.category_id, d.name, d.name_ru, d.source, d.description, d.price
         FROM dishes d
         JOIN venues v ON v.id = d.venue_id
         WHERE d.is_available = 1 AND v.is_active = 1
         ORDER BY d.category_id, d.id'
    );
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $dishes[] = [
            'id' => (int) $row['id'],
            'venue_id' => (int) $row['venue_id'],
            'category_id' => (int) $row['category_id'],
            'name' => (string) $row['name'],
            // Русское название заполняет администратор для блюд с сайта,
            // у ручных блюд его нет.
            'name_ru' => (string) ($row['name_ru'] ?? ''),
            'source' => (string) $row['source'],
            'description' => (string) ($row['description'] ?? ''),
            'price' => (float) $row['price'],
        ];
    }
} catch (Throwable $error) {
    // Техническую причину наружу не отдаём: пользователю важно только то,
    // что каталог временно недоступен.
    $catalogError = 'Каталог временно недоступен. Попробуйте обновить страницу позже.';
    $venues = [];
    $categories = [];
    $dishes = [];
}
