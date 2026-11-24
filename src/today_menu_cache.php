<?php
/**
 * Кэш меню market.grsu.by для страницы «Меню на сегодня».
 *
 * Внешний сайт отвечает медленно (иногда десятки секунд), поэтому готовый
 * разбор храним в JSON-файле во временной папке PHP. Это не база данных:
 * файл нужен только для скорости и пропадает при пересоздании контейнера.
 */

require_once __DIR__ . '/external_menu.php';

// Сколько секунд считаем кэш свежим. Час — компромисс: меню меняется раз в день.
const TODAY_MENU_CACHE_TTL = 3600;

/**
 * Возвращает путь к файлу кэша.
 *
 * @return string Абсолютный путь к JSON-файлу.
 */
function getTodayMenuCacheFile(): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'unifood_today_menu.json';
}

/**
 * Читает сохранённый разбор меню.
 *
 * @return array{canteens: array<int, array{name: string, images: array<int, string>}>, cached_at: int}|null
 *         Данные кэша или null, если файла нет или он испорчен.
 */
function readTodayMenuCache(): ?array
{
    $file = getTodayMenuCacheFile();

    if (!is_file($file)) {
        return null;
    }

    $json = @file_get_contents($file);

    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);

    if (!is_array($data) || !isset($data['canteens']) || !is_array($data['canteens'])) {
        return null;
    }

    return [
        'canteens' => $data['canteens'],
        'cached_at' => (int) ($data['cached_at'] ?? 0),
    ];
}

/**
 * Сохраняет разбор меню в файл.
 *
 * Сначала пишем во временный файл и только потом переименовываем: так
 * параллельный запрос не прочитает недописанный JSON.
 *
 * @param array<int, array{name: string, images: array<int, string>}> $canteens Список столовых.
 * @return void
 */
function writeTodayMenuCache(array $canteens): void
{
    $file = getTodayMenuCacheFile();
    $json = json_encode(
        ['canteens' => $canteens, 'cached_at' => time()],
        JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        return;
    }

    $temporaryFile = $file . '.tmp';

    if (@file_put_contents($temporaryFile, $json) === false) {
        return;
    }

    @rename($temporaryFile, $file);
}

/**
 * Отдаёт меню из кэша или загружает его с внешнего сайта.
 *
 * Если сайт недоступен, но есть старый кэш, возвращаем его и помечаем
 * данные устаревшими — страница покажет предупреждение, но останется рабочей.
 *
 * @return array{canteens: array<int, array{name: string, images: array<int, string>}>, error: string, stale: bool}
 *         Список столовых, текст ошибки и признак устаревших данных.
 */
function getTodayMenu(): array
{
    $cache = readTodayMenuCache();
    $cacheIsFresh = $cache !== null && (time() - $cache['cached_at']) < TODAY_MENU_CACHE_TTL;

    if ($cacheIsFresh) {
        return ['canteens' => $cache['canteens'], 'error' => '', 'stale' => false];
    }

    try {
        $html = fetchRussianMenuHtml();
        $menu = parseRussianMenu($html);
        writeTodayMenuCache($menu['canteens']);

        return ['canteens' => $menu['canteens'], 'error' => '', 'stale' => false];
    } catch (RuntimeException $error) {
        // Техническую причину пишем в лог, пользователю показываем понятный текст.
        error_log('Не удалось загрузить меню market.grsu.by: ' . $error->getMessage());

        if ($cache !== null && $cache['canteens'] !== []) {
            return [
                'canteens' => $cache['canteens'],
                'error' => 'Не удалось обновить меню. Показаны последние загруженные данные.',
                'stale' => true,
            ];
        }

        return [
            'canteens' => [],
            'error' => 'Не удалось получить меню с сайта market.grsu.by. Попробуйте обновить страницу позже.',
            'stale' => false,
        ];
    }
}
