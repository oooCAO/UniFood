<?php
/**
 * CLI-скрипт ручной проверки импорта меню с market.grsu.by.
 *
 * Скрипт только скачивает и разбирает меню. В базу он ничего не пишет:
 * синхронизация с каталогом появится в отдельной задаче.
 *
 * Запуск:
 *   php bin/import_menu.php --dry-run
 *   php bin/import_menu.php --json
 */

require_once __DIR__ . '/../src/external_menu.php';

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
    echo "  php bin/import_menu.php --dry-run   Загрузить меню и показать сводку\n";
    echo "  php bin/import_menu.php --json      Вывести разобранные данные в JSON\n";
    echo "  php bin/import_menu.php --help      Показать эту подсказку\n";
}

/**
 * Печатает краткую сводку по разобранному меню.
 *
 * @param array $russian Результат parseRussianMenu().
 * @param array $english Результат parseEnglishMenu().
 * @return void
 */
function printImportMenuSummary(array $russian, array $english): void
{
    $russianCanteens = count($russian['canteens']);
    $russianImages = 0;

    foreach ($russian['canteens'] as $canteen) {
        $russianImages += count($canteen['images']);
    }

    $englishCanteens = count($english['canteens']);
    $categoriesCount = 0;
    $dishesCount = 0;
    $examples = [];

    foreach ($english['canteens'] as $canteen) {
        $categoriesCount += count($canteen['categories']);

        foreach ($canteen['categories'] as $category) {
            $dishesCount += count($category['dishes']);

            foreach ($category['dishes'] as $dish) {
                if (count($examples) >= 5) {
                    continue;
                }

                $examples[] = $dish;
            }
        }
    }

    echo "Меню загружено и разобрано.\n";
    echo "Русская версия: столовых — {$russianCanteens}, картинок — {$russianImages}.\n";
    echo "Английская версия: столовых — {$englishCanteens}, категорий — {$categoriesCount}, блюд — {$dishesCount}.\n";

    if ($examples === []) {
        return;
    }

    echo "\nПримеры блюд:\n";

    foreach ($examples as $dish) {
        $price = $dish['price'] !== null ? number_format($dish['price'], 2, '.', '') : 'цена не указана';
        $details = [];

        if ($dish['kcal'] !== null) {
            $details[] = $dish['kcal'] . ' ккал';
        }

        if ($dish['output'] !== '') {
            $details[] = 'выход ' . $dish['output'];
        }

        $suffix = $details !== [] ? ' (' . implode(', ', $details) . ')' : '';
        echo "  - {$dish['name']} — {$price}{$suffix}\n";
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

$dryRun = in_array('--dry-run', $arguments, true);
$outputJson = in_array('--json', $arguments, true);

try {
    $russianHtml = fetchRussianMenuHtml();
    $englishHtml = fetchEnglishMenuHtml();

    $russianMenu = parseRussianMenu($russianHtml);
    $englishMenu = parseEnglishMenu($englishHtml);
} catch (Throwable $error) {
    // Без понятного сообщения cron в будущем будет молча падать.
    fwrite(STDERR, 'Ошибка импорта меню: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

if ($outputJson) {
    $debugData = [
        'russian' => $russianMenu,
        'english' => $englishMenu,
    ];

    echo json_encode($debugData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

if (!$dryRun) {
    fwrite(STDERR, 'Запись в БД будет добавлена позже. Сейчас доступны только --dry-run и --json.' . PHP_EOL);
    exit(1);
}

printImportMenuSummary($russianMenu, $englishMenu);
