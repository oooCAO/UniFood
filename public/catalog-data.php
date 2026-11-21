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

    $stmt = $connection->prepare('SELECT id, venue_id, name FROM categories ORDER BY venue_id, sort_order, id');
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $categories[] = [
            'id' => (int) $row['id'],
            'venue_id' => (int) $row['venue_id'],
            'name' => (string) $row['name'],
        ];
    }

    // Недоступные блюда в каталоге не показываем: их нельзя заказать.
    $stmt = $connection->prepare(
        'SELECT id, venue_id, category_id, name, description, price FROM dishes WHERE is_available = 1 ORDER BY category_id, id'
    );
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $dishes[] = [
            'id' => (int) $row['id'],
            'venue_id' => (int) $row['venue_id'],
            'category_id' => (int) $row['category_id'],
            'name' => (string) $row['name'],
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
