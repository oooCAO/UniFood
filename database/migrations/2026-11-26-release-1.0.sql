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
