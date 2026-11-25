-- Тестовые данные для локальной разработки.
-- Выполняется один раз после 01_schema.sql, тоже только на пустой базе.
-- Пароль у всех тестовых пользователей один: Test1234! (хеш ниже — bcrypt от него,
-- сам пароль не хранится и нужен только для входа при проверке в Docker).

-- Без этой строки MySQL при первом запуске читает файл как latin1, и кириллица
-- в названиях заведений, категорий и блюд превращается в "мусор" (проверено:
-- без SET NAMES кириллица бьётся даже при is_active = 1 у всех строк).
SET NAMES utf8mb4;

INSERT INTO users (email, password_hash, full_name, role) VALUES
    ('student@unifood.test', '$2y$10$N1FOMTNPXELaQy90mtTlqON4NOmhJaDRBFFG1qSdedQ53GUf3SOYS', 'Тестовый Студент', 'student'),
    ('staff@unifood.test', '$2y$10$N1FOMTNPXELaQy90mtTlqON4NOmhJaDRBFFG1qSdedQ53GUf3SOYS', 'Тестовый Сотрудник', 'staff'),
    ('admin@unifood.test', '$2y$10$N1FOMTNPXELaQy90mtTlqON4NOmhJaDRBFFG1qSdedQ53GUf3SOYS', 'Тестовый Администратор', 'admin');

-- Два реальных заведения ГрГУ (адреса с market.grsu.by). У «Главного корпуса»
-- на сайте есть английская таблица меню — для него работает автоимпорт
-- (menu_sources ниже). У «Захарова» меню на сайте только картинкой —
-- блюда этого заведения ведёт администратор вручную.
-- menu_image_code — код заведения в имени файла картинки меню на
-- market.grsu.by (проверено вручную 25.11.2026: oz25.11.2026.jpg,
-- zah25.11.2026.jpg). Полный URL с сегодняшней датой собирает
-- buildMenuImageUrl() в src/venues.php, в БД готовая ссылка не хранится.
INSERT INTO venues (id, name, address, is_active, menu_image_code, menu_text) VALUES
    (1, 'Столовая Главного корпуса', 'ул. Ожешко, 22', 1,
        'oz', 'Меню столовой Главного корпуса ГрГУ на ул. Ожешко, 22.'),
    (2, 'Столовая Захарова', 'ул. Захарова, 32', 1,
        'zah', NULL);

-- Отдельным UPDATE, а не в INSERT INTO users: на момент вставки users
-- строки venues ещё не существуют, FK бы не дал вставить venue_id сразу.
UPDATE users SET venue_id = 1 WHERE email = 'staff@unifood.test';

-- source_name — ровно та строка, которую parseEnglishMenu() достаёт из
-- <summary> английской версии страницы (проверено реальной загрузкой
-- market.grsu.by). Для «Захарова» строки нет: там нет английской таблицы.
INSERT INTO menu_sources (venue_id, source_name) VALUES
    (1, '1. Canteen №1 of GrSU, Ozheshko 22');

INSERT INTO categories (id, venue_id, name, sort_order) VALUES
    (1, 1, 'Горячее', 1),
    (2, 1, 'Напитки', 2),
    (3, 2, 'Выпечка', 1),
    (4, 2, 'Напитки', 2);

-- Одно блюдо сделано недоступным (is_available = 0), чтобы проверить,
-- что такие блюда не попадают в заказ.
INSERT INTO dishes (venue_id, category_id, name, description, price, is_available) VALUES
    (1, 1, 'Плов', 'Рис с курицей и овощами', 5.50, 1),
    (1, 1, 'Паста с курицей', 'Паста, курица, сливочный соус', 6.20, 0),
    (1, 2, 'Чай', 'Чёрный или зелёный', 1.00, 1),
    (1, 2, 'Сок апельсиновый', '0.2 л', 1.50, 1),
    (2, 3, 'Круассан', 'С шоколадной начинкой', 2.00, 1),
    (2, 3, 'Пирожок с вишней', '', 1.80, 1),
    (2, 4, 'Кофе', 'Americano', 2.50, 1);

-- Слоты на 7 дней вперёд от момента создания базы, а не на фиксированные
-- даты: иначе через несколько дней после docker compose up все слоты
-- окажутся в прошлом, и студент не сможет оформить заказ ни на одно время.
-- days — таблица смещений 0..6, slot_templates — время/вместимость на
-- каждый день для каждого заведения; CROSS JOIN даёт по одному набору
-- слотов на каждый из 7 дней.
INSERT INTO pickup_slots (venue_id, slot_date, start_time, end_time, capacity, is_active)
WITH RECURSIVE days(offset_days) AS (
    SELECT 0
    UNION ALL
    SELECT offset_days + 1 FROM days WHERE offset_days < 6
)
SELECT slot_templates.venue_id,
       CURDATE() + INTERVAL days.offset_days DAY,
       slot_templates.start_time,
       slot_templates.end_time,
       slot_templates.capacity,
       slot_templates.is_active
FROM days
CROSS JOIN (
    SELECT 1 AS venue_id, '12:00:00' AS start_time, '12:15:00' AS end_time, 20 AS capacity, 1 AS is_active
    UNION ALL
    SELECT 1, '12:15:00', '12:30:00', 20, 1
    UNION ALL
    SELECT 2, '13:00:00', '13:15:00', 15, 1
) AS slot_templates;

-- Один неактивный слот только на сегодня — для проверки, что такой слот
-- нельзя выбрать. На все 7 дней он не нужен, поэтому не в CROSS JOIN выше.
INSERT INTO pickup_slots (venue_id, slot_date, start_time, end_time, capacity, is_active) VALUES
    (1, CURDATE(), '08:00:00', '08:15:00', 20, 0);
