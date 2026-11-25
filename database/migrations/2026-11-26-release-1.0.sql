-- Миграция для релиза 1.0: картинка и текст меню заведения (П.3).
-- Применять на уже работающей базе (database/init/ выполняется только
-- на пустом томе и живую базу не трогает). Перед запуском сделать дамп:
--   docker compose exec -T mysql mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" \
--     "$MYSQL_DATABASE" > unifood_before_release.sql
-- Применение:
--   docker compose exec -T mysql mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" \
--     "$MYSQL_DATABASE" < database/migrations/2026-11-26-release-1.0.sql
-- Повторный запуск на уже мигрированной базе останавливается с понятной
-- ошибкой MySQL "Duplicate column name" — колонки не задвоятся.

ALTER TABLE venues
    ADD COLUMN menu_image_code VARCHAR(10) NULL
        COMMENT 'Код заведения в имени файла картинки меню на market.grsu.by',
    ADD COLUMN menu_text TEXT NULL
        COMMENT 'Текст меню для страницы заведения, заполняет админ';

-- ---------------------------------------------------------------------------
-- Слоты на сегодня (П.8, задача 2 Разработчика 2): уникальный ключ
-- (venue_id, slot_date, start_time) на pickup_slots.
--
-- На живой базе уже могут быть дубли слотов (старый сид генерировал слоты
-- на 7 дней вперёд, часть запусков могла пересекаться). ALTER IGNORE в
-- MySQL 8 убрали, поэтому дубли убираем вручную через временную таблицу
-- (id, keep_id) по решению C10:
--   1. найти дубли по (venue_id, slot_date, start_time);
--   2. перепривязать orders.slot_id с дублей на оставляемый слот
--      (слот с заказами удалить нельзя — нарушит FK);
--   3. удалить дубли;
--   4. добавить UNIQUE KEY.
-- Повторный запуск на уже мигрированной базе: шаги 1–3 ничего не находят
-- (дублей уже нет), а ADD UNIQUE KEY останавливается с понятной ошибкой
-- MySQL "Duplicate key name" — ключ не задваивается.
-- ---------------------------------------------------------------------------

-- 1. Для каждой группы дублей запоминаем минимальный id — его оставляем,
-- остальные id этой группы идут на удаление.
CREATE TEMPORARY TABLE tmp_slot_dupes (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    keep_id INT UNSIGNED NOT NULL
);

INSERT INTO tmp_slot_dupes (id, keep_id)
SELECT ps.id, keep_slot.keep_id
FROM pickup_slots AS ps
JOIN (
    SELECT venue_id, slot_date, start_time, MIN(id) AS keep_id
    FROM pickup_slots
    GROUP BY venue_id, slot_date, start_time
    HAVING COUNT(*) > 1
) AS keep_slot
  ON keep_slot.venue_id = ps.venue_id
 AND keep_slot.slot_date = ps.slot_date
 AND keep_slot.start_time = ps.start_time
WHERE ps.id <> keep_slot.keep_id;

-- 2. Заказы, ссылавшиеся на удаляемый дубль, переводим на оставляемый слот.
UPDATE orders
JOIN tmp_slot_dupes ON tmp_slot_dupes.id = orders.slot_id
SET orders.slot_id = tmp_slot_dupes.keep_id;

-- 3. Сами дубли удаляем — после шага 2 на них больше ничего не ссылается.
DELETE FROM pickup_slots WHERE id IN (SELECT id FROM tmp_slot_dupes);

DROP TEMPORARY TABLE tmp_slot_dupes;

-- 4. Дублей нет — ключ добавляется без ошибок.
ALTER TABLE pickup_slots
    ADD UNIQUE KEY uq_pickup_slots_venue_date_start (venue_id, slot_date, start_time);
