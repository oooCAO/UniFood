<?php
/**
 * CLI-скрипт импорта меню с market.grsu.by в каталог блюд.
 *
 * Запуск:
 *   php bin/import_menu.php              Выполнить импорт (пишет в БД)
 *   php bin/import_menu.php --dry-run    Показать, что будет сделано, без записи в БД
 *   php bin/import_menu.php --json       Вывести разобранные сайтом данные в JSON (без БД)
 *   php bin/import_menu.php --help       Показать подсказку
 */

require_once __DIR__ . '/../src/external_menu.php';
require_once __DIR__ . '/../src/menu_import.php';

// Скрипт запускается только из консоли: из браузера он не должен быть доступен.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

/**
 * Выводит подсказку по запуску скрипта.
 *
 * @return void
 */
function printImportMenuUsage(): void
{
    echo "Использование:\n";
    echo "  php bin/import_menu.php              Выполнить импорт (пишет в БД)\n";
    echo "  php bin/import_menu.php --dry-run    Показать, что будет сделано, без записи в БД\n";
    echo "  php bin/import_menu.php --json       Вывести разобранные сайтом данные в JSON\n";
    echo "  php bin/import_menu.php --help       Показать эту подсказку\n";
}

/**
 * Печатает результат импорта (настоящего или пробного).
 *
 * @param array{success: bool, added: int, updated: int, hidden: int, skipped_sources: array<int, string>, error: string|null} $result
 *   Результат runMenuImport().
 * @param bool $dryRun Пробный запуск или настоящий — меняет только текст заголовка.
 * @return void
 */
function printImportMenuResult(array $result, bool $dryRun): void
{
    if (!$result['success']) {
        echo 'Импорт не выполнен: ' . $result['error'] . PHP_EOL;
        return;
    }

    echo $dryRun
        ? "Пробный запуск (в БД ничего не записано):\n"
        : "Импорт выполнен:\n";
    echo "  добавлено: {$result['added']}\n";
    echo "  обновлено: {$result['updated']}\n";
    echo "  скрыто (пропали из меню): {$result['hidden']}\n";

    if ($result['skipped_sources'] === []) {
        return;
    }

    echo "\nСтоловые без сопоставления в menu_sources (пропущены):\n";

    foreach ($result['skipped_sources'] as $sourceName) {
        echo "  - {$sourceName}\n";
    }
}

$arguments = array_slice($argv, 1);
$knownArguments = ['--dry-run', '--json', '--help'];
$unknownArguments = array_diff($arguments, $knownArguments);

if ($unknownArguments !== []) {
    fwrite(STDERR, 'Неизвестные параметры: ' . implode(', ', $unknownArguments) . PHP_EOL);
    printImportMenuUsage();
    exit(1);
}

if (in_array('--help', $arguments, true)) {
    printImportMenuUsage();
    exit(0);
}

if (in_array('--json', $arguments, true)) {
    try {
        $russianMenu = parseRussianMenu(fetchRussianMenuHtml());
        $englishMenu = parseEnglishMenu(fetchEnglishMenuHtml());
    } catch (Throwable $error) {
        fwrite(STDERR, 'Ошибка загрузки меню: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }

    echo json_encode(['russian' => $russianMenu, 'english' => $englishMenu], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$dryRun = in_array('--dry-run', $arguments, true);
$result = runMenuImport($dryRun);

printImportMenuResult($result, $dryRun);
exit($result['success'] ? 0 : 1);
