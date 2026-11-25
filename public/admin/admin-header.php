<?php
/**
 * Общая шапка страниц админки.
 *
 * Админка — отдельный интерфейс, не часть обычной навигации сайта:
 * не подключает header.php и style.css со страниц каталога. Каждая
 * страница админки уже вызвала requireRole(ROLE_ADMIN) до этого include
 * и задала $pageTitle.
 */
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Админка UniFood') ?></title>
    <link rel="stylesheet" href="/css/admin.css">
</head>
<body>
<header class="admin-header">
    <div class="admin-header-inner">
        <span class="admin-logo">UniFood: админка</span>
        <nav class="admin-nav">
            <a href="/admin/venues.php">Заведения</a>
            <a href="/admin/categories.php">Категории</a>
            <a href="/admin/dishes.php">Блюда</a>
            <a href="/admin/users.php">Пользователи</a>
            <a href="/admin/slots.php">Слоты</a>
            <a href="/admin/statistics.php">Статистика</a>
            <a href="/admin/menu-import.php">Импорт меню</a>
            <a href="/">На сайт</a>
            <a href="/logout.php">Выйти</a>
        </nav>
    </div>
</header>

<main class="admin-container">
