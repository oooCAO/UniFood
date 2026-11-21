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

INSERT INTO venues (id, name, address, is_active) VALUES
    (1, 'Столовая Главного корпуса', 'ул. Университетская, 1', 1),
    (2, 'Кафе Политехнического корпуса', 'ул. Советская, 10', 1);

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

-- Слоты на сегодня и завтра: часть активна, один — нет (для проверки, что
-- неактивный слот нельзя выбрать).
INSERT INTO pickup_slots (venue_id, slot_date, start_time, end_time, capacity, is_active) VALUES
    (1, CURDATE(), '12:00:00', '12:15:00', 20, 1),
    (1, CURDATE(), '12:15:00', '12:30:00', 20, 1),
    (1, CURDATE() + INTERVAL 1 DAY, '12:00:00', '12:15:00', 20, 1),
    (1, CURDATE(), '08:00:00', '08:15:00', 20, 0),
    (2, CURDATE(), '13:00:00', '13:15:00', 15, 1),
    (2, CURDATE() + INTERVAL 1 DAY, '13:00:00', '13:15:00', 15, 1);
