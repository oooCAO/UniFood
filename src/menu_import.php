<?php
/**
 * Синхронизация меню с market.grsu.by с каталогом блюд.
 *
 * Источник — только английская версия сайта (parseEnglishMenu() из
 * src/external_menu.php): там меню текстом, а не картинкой. Русская версия
 * используется отдельно для страницы «Меню на сегодня» (Dev3), сюда не
 * относится.
 *
 * Заведение без строки в menu_sources просто пропускается — новые заведения
 * эта синхронизация не создаёт.
 */

require_once __DIR__ . '/db_mysql.php';
require_once __DIR__ . '/external_menu.php';

/** Категория, в которую попадают импортированные блюда без точного совпадения по названию. */
const MENU_IMPORT_FALLBACK_CATEGORY = 'Меню с сайта';

/**
 * Возвращает id заведения по названию столовой с сайта.
 *
 * @param string $sourceName Название, как его отдаёт <summary> на английской версии сайта.
 * @return int|null venue_id или null, если источник не найден или выключен.
 */
function getMenuSourceVenueId(string $sourceName): ?int
{
    $stmt = getMysqlConnection()->prepare(
        'SELECT venue_id FROM menu_sources WHERE source_name = ? AND is_active = 1'
    );
    $stmt->execute([$sourceName]);

    $venueId = $stmt->fetchColumn();

    return $venueId === false ? null : (int) $venueId;
}

/**
 * Возвращает категорию каталога для импортированного блюда, создавая её при необходимости.
 *
 * Если в заведении уже есть категория с таким же названием, как на сайте
 * (например, администратор завёл её вручную заранее), используем её —
 * так группировка получается точнее. Если нет — все такие блюда идут
 * в одну общую категорию "Меню с сайта" на заведение, чтобы не плодить
 * десятки категорий с английскими названиями при каждом запуске.
 *
 * @param int $venueId Id заведения.
 * @param string $categoryName Название категории с сайта (например, "Hot dishes").
 * @return int Id категории каталога.
 */
function getOrCreateImportCategory(int $venueId, string $categoryName): int
{
    $pdo = getMysqlConnection();

    $stmt = $pdo->prepare('SELECT id FROM categories WHERE venue_id = ? AND name = ?');
    $stmt->execute([$venueId, $categoryName]);
    $categoryId = $stmt->fetchColumn();

    if ($categoryId !== false) {
        return (int) $categoryId;
    }

    $stmt->execute([$venueId, MENU_IMPORT_FALLBACK_CATEGORY]);
    $fallbackId = $stmt->fetchColumn();

    if ($fallbackId !== false) {
        return (int) $fallbackId;
    }

    $insert = $pdo->prepare('INSERT INTO categories (venue_id, name, sort_order) VALUES (?, ?, 100)');
    $insert->execute([$venueId, MENU_IMPORT_FALLBACK_CATEGORY]);

    return (int) $pdo->lastInsertId();
}

/**
 * Строит ключ блюда для повторного импорта.
 *
 * Хеш от заведения, категории с сайта и названия блюда — без цены: смена
 * цены не делает блюдо другим. Названия приводим к одному регистру, чтобы
 * «Soup» и «soup» не считались разными блюдами.
 *
 * @param int $venueId Id заведения.
 * @param string $categoryName Название категории с сайта.
 * @param string $dishName Название блюда с сайта.
 * @return string Хеш SHA-1.
 */
function buildDishExternalKey(int $venueId, string $categoryName, string $dishName): string
{
    $normalized = $venueId . '|' . mb_strtolower(trim($categoryName)) . '|' . mb_strtolower(trim($dishName));

    return sha1($normalized);
}

/**
 * Добавляет новое блюдо или обновляет уже импортированное по external_key.
 *
 * Ручные блюда (source = manual) сюда не попадают: их external_key всегда
 * NULL, совпадение по хешу невозможно. name_ru не трогаем ни при вставке
 * (сайт отдаёт только английские названия), ни при обновлении (его заполняет
 * администратор).
 *
 * @param int $venueId Id заведения.
 * @param int $categoryId Id категории каталога.
 * @param string $externalKey Ключ блюда, см. buildDishExternalKey().
 * @param array{name: string, ingredients: string, price: float|null} $dish Разобранное блюдо.
 * @return string 'added' или 'updated'.
 */
function importMenuDish(int $venueId, int $categoryId, string $externalKey, array $dish): string
{
    $pdo = getMysqlConnection();

    $stmt = $pdo->prepare('SELECT id FROM dishes WHERE external_key = ?');
    $stmt->execute([$externalKey]);
    $existingId = $stmt->fetchColumn();

    if ($existingId !== false) {
        $update = $pdo->prepare(
            'UPDATE dishes SET name = ?, description = ?, price = ?, category_id = ?, venue_id = ?, is_available = 1
             WHERE id = ?'
        );
        $update->execute([
            $dish['name'],
            $dish['ingredients'],
            $dish['price'],
            $categoryId,
            $venueId,
            $existingId,
        ]);

        return 'updated';
    }

    $insert = $pdo->prepare(
        "INSERT INTO dishes (venue_id, category_id, name, description, price, source, external_key)
         VALUES (?, ?, ?, ?, ?, 'market', ?)"
    );
    $insert->execute([$venueId, $categoryId, $dish['name'], $dish['ingredients'], $dish['price'], $externalKey]);

    return 'added';
}

/**
 * Скрывает импортированные блюда заведения, которых не было в последнем меню.
 *
 * Трогает только source = market: ручные блюда этой функции не касаются.
 * Удаления нет — на блюдо могут ссылаться старые order_items.
 *
 * @param int $venueId Id заведения.
 * @param array<int, string> $seenKeys Ключи блюд, встреченных в текущем импорте.
 * @return int Сколько блюд скрыто.
 */
function hideMissingMarketDishes(int $venueId, array $seenKeys): int
{
    $pdo = getMysqlConnection();

    if (empty($seenKeys)) {
        $stmt = $pdo->prepare(
            "UPDATE dishes SET is_available = 0 WHERE venue_id = ? AND source = 'market' AND is_available = 1"
        );
        $stmt->execute([$venueId]);

        return $stmt->rowCount();
    }

    $placeholders = implode(', ', array_fill(0, count($seenKeys), '?'));

    $stmt = $pdo->prepare(
        "UPDATE dishes SET is_available = 0
         WHERE venue_id = ? AND source = 'market' AND is_available = 1
           AND external_key NOT IN ($placeholders)"
    );
    $stmt->execute(array_merge([$venueId], $seenKeys));

    return $stmt->rowCount();
}

/**
 * Скачивает, разбирает и синхронизирует меню со всеми активными источниками.
 *
 * Запись в menu_import_runs всегда происходит отдельно от основной
 * транзакции с каталогом: журнал импорта должен сохраниться, даже если
 * сама синхронизация откатилась (ошибка) или это пробный запуск (dry-run).
 *
 * @param bool $dryRun Если true, в конце откатывает транзакцию с каталогом
 *   и не пишет строку в menu_import_runs — используется для --dry-run
 *   и кнопки «Обновить сейчас» с предварительным просмотром.
 * @return array{success: bool, added: int, updated: int, hidden: int,
 *               skipped_sources: array<int, string>, error: string|null} Результат запуска.
 */
function runMenuImport(bool $dryRun = false): array
{
    $pdo = getMysqlConnection();
    $added = 0;
    $updated = 0;
    $hidden = 0;
    $skippedSources = [];

    $runId = null;

    if (!$dryRun) {
        $runStmt = $pdo->prepare("INSERT INTO menu_import_runs (status) VALUES ('running')");
        $runStmt->execute();
        $runId = (int) $pdo->lastInsertId();
    }

    try {
        $html = fetchEnglishMenuHtml();
        $menu = parseEnglishMenu($html);

        $pdo->beginTransaction();

        foreach ($menu['canteens'] as $canteen) {
            $venueId = getMenuSourceVenueId($canteen['name']);

            if ($venueId === null) {
                $skippedSources[] = $canteen['name'];
                continue;
            }

            $seenKeys = [];

            foreach ($canteen['categories'] as $category) {
                $categoryId = getOrCreateImportCategory($venueId, $category['name']);

                foreach ($category['dishes'] as $dish) {
                    // Блюдо без цены нельзя корректно поставить в заказ, пропускаем.
                    if ($dish['price'] === null) {
                        continue;
                    }

                    $externalKey = buildDishExternalKey($venueId, $category['name'], $dish['name']);
                    $seenKeys[] = $externalKey;

                    if (importMenuDish($venueId, $categoryId, $externalKey, $dish) === 'added') {
                        $added++;
                    } else {
                        $updated++;
                    }
                }
            }

            $hidden += hideMissingMarketDishes($venueId, $seenKeys);
        }

        if ($dryRun) {
            $pdo->rollBack();
        } else {
            $pdo->commit();

            $finishStmt = $pdo->prepare(
                "UPDATE menu_import_runs
                 SET finished_at = CURRENT_TIMESTAMP, status = 'success',
                     dishes_added = ?, dishes_updated = ?, dishes_hidden = ?
                 WHERE id = ?"
            );
            $finishStmt->execute([$added, $updated, $hidden, $runId]);
        }

        return [
            'success' => true,
            'added' => $added,
            'updated' => $updated,
            'hidden' => $hidden,
            'skipped_sources' => $skippedSources,
            'error' => null,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Ошибка импорта меню: ' . $e->getMessage());

        if (!$dryRun) {
            $failStmt = $pdo->prepare(
                "UPDATE menu_import_runs SET finished_at = CURRENT_TIMESTAMP, status = 'failed', error_text = ? WHERE id = ?"
            );
            $failStmt->execute([$e->getMessage(), $runId]);
        }

        return [
            'success' => false,
            'added' => 0,
            'updated' => 0,
            'hidden' => 0,
            'skipped_sources' => $skippedSources,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Возвращает последний запуск импорта для страницы «Импорт меню».
 *
 * @return array|null Строка menu_import_runs или null, если импорт ещё не запускали.
 */
function getLatestMenuImportRun(): ?array
{
    $stmt = getMysqlConnection()->query(
        'SELECT id, started_at, finished_at, status, dishes_added, dishes_updated, dishes_hidden, error_text
         FROM menu_import_runs
         ORDER BY id DESC
         LIMIT 1'
    );

    $run = $stmt->fetch();

    return $run === false ? null : $run;
}

/**
 * Возвращает импортированные блюда для редактирования русского названия.
 *
 * @return array Список блюд source = market: id, name, name_ru, price, venue_name.
 */
function listMarketDishes(): array
{
    $stmt = getMysqlConnection()->query(
        "SELECT dishes.id, dishes.name, dishes.name_ru, dishes.price, venues.name AS venue_name
         FROM dishes
         JOIN venues ON venues.id = dishes.venue_id
         WHERE dishes.source = 'market'
         ORDER BY venues.name, dishes.name"
    );

    return $stmt->fetchAll();
}

/**
 * Возвращает источник блюда по id, не фильтруя по source.
 *
 * Нужна только для текста ошибки на странице «Импорт меню»: отличить
 * «блюда с таким id вообще нет» от «блюдо есть, но оно не с сайта».
 *
 * @param int $dishId Id блюда.
 * @return string|null 'manual'/'market' или null, если блюда с таким id нет.
 */
function getDishSource(int $dishId): ?string
{
    $stmt = getMysqlConnection()->prepare('SELECT source FROM dishes WHERE id = ?');
    $stmt->execute([$dishId]);

    $source = $stmt->fetchColumn();

    return $source === false ? null : $source;
}

/**
 * Сохраняет русское название импортированного блюда.
 *
 * Только для source = market: у ручных блюд название уже на русском,
 * отдельный перевод им не нужен.
 *
 * @param int $dishId Id блюда.
 * @param string $nameRu Русское название. Пустая строка сохраняется как NULL.
 * @return bool true, если блюдо с таким id и source = market найдено и обновлено.
 */
function updateDishNameRu(int $dishId, string $nameRu): bool
{
    $nameRu = trim($nameRu);

    $stmt = getMysqlConnection()->prepare(
        "UPDATE dishes SET name_ru = ? WHERE id = ? AND source = 'market'"
    );
    $stmt->execute([$nameRu === '' ? null : $nameRu, $dishId]);

    return $stmt->rowCount() > 0;
}
