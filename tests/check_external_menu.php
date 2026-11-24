<?php
/**
 * Проверка разбора внешнего меню на локальных HTML-фикстурах.
 *
 * Скрипт запускается вручную из консоли и не требует PHPUnit:
 *   php tests/check_external_menu.php
 *
 * Если проверка не прошла, скрипт печатает список ошибок и возвращает код 1.
 */

require_once __DIR__ . '/../src/external_menu.php';

$failures = [];

/**
 * Проверяет условие и запоминает ошибку, если оно не выполнено.
 *
 * @param bool $condition Проверяемое условие.
 * @param string $message Описание проверки.
 * @return void
 */
function checkCondition(bool $condition, string $message): void
{
    global $failures;

    if ($condition) {
        echo 'OK: ' . $message . PHP_EOL;
        return;
    }

    $failures[] = $message;
    echo 'FAIL: ' . $message . PHP_EOL;
}

/**
 * Сравнивает два числа с небольшой погрешностью.
 *
 * @param float|null $actual Полученное значение.
 * @param float $expected Ожидаемое значение.
 * @param string $message Описание проверки.
 * @return void
 */
function checkFloat(?float $actual, float $expected, string $message): void
{
    checkCondition(
        $actual !== null && abs($actual - $expected) < 0.001,
        $message
    );
}

/**
 * Ищет столовую по названию.
 *
 * @param array $menu Результат разбора меню.
 * @param string $name Название столовой.
 * @return array|null Найденная столовая или null.
 */
function findCanteen(array $menu, string $name): ?array
{
    foreach ($menu['canteens'] as $canteen) {
        if ($canteen['name'] === $name) {
            return $canteen;
        }
    }

    return null;
}

/**
 * Ищет категорию по названию внутри столовой.
 *
 * @param array $canteen Столовая.
 * @param string $name Название категории.
 * @return array|null Найденная категория или null.
 */
function findCategory(array $canteen, string $name): ?array
{
    foreach ($canteen['categories'] as $category) {
        if ($category['name'] === $name) {
            return $category;
        }
    }

    return null;
}

/**
 * Ищет блюдо по названию внутри категории.
 *
 * @param array $category Категория.
 * @param string $name Название блюда.
 * @return array|null Найденное блюдо или null.
 */
function findDish(array $category, string $name): ?array
{
    foreach ($category['dishes'] as $dish) {
        if ($dish['name'] === $name) {
            return $dish;
        }
    }

    return null;
}

$russianHtml = file_get_contents(__DIR__ . '/fixtures/menu_ru.html');
$englishHtml = file_get_contents(__DIR__ . '/fixtures/menu_en.html');

if ($russianHtml === false || $englishHtml === false) {
    fwrite(STDERR, 'Не удалось прочитать HTML-фикстуры.' . PHP_EOL);
    exit(1);
}

try {
    $russianMenu = parseRussianMenu($russianHtml);
    $englishMenu = parseEnglishMenu($englishHtml);
} catch (Throwable $error) {
    fwrite(STDERR, 'Ошибка разбора фикстур: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

// --- Русская версия: столовые и картинки ---
checkCondition(count($russianMenu['canteens']) === 2, 'В русской версии две столовые с картинками');

$firstRussianCanteen = findCanteen($russianMenu, '1. Столовая на Ожешко, 22');
checkCondition($firstRussianCanteen !== null, 'Название первой столовой очищено от &nbsp;');

if ($firstRussianCanteen !== null) {
    checkCondition(count($firstRussianCanteen['images']) === 2, 'Служебная картинка button.jpg не попала в список');
    checkCondition(
        in_array('https://market.grsu.by/image/catalog/menustolovaya/oz23.11.2026.jpg', $firstRussianCanteen['images'], true),
        'Абсолютная ссылка на картинку сохранена'
    );
    checkCondition(
        in_array('https://market.grsu.by/image/catalog/menustolovaya/oz23.11.2026-1.jpg', $firstRussianCanteen['images'], true),
        'Относительная ссылка на картинку стала абсолютной'
    );
}

$secondRussianCanteen = findCanteen($russianMenu, '2. Буфет «Тест»');
checkCondition($secondRussianCanteen !== null, 'HTML-сущности в названии столовой раскрыты');

if ($secondRussianCanteen !== null) {
    checkCondition(
        $secondRussianCanteen['images'] === ['https://market.grsu.by/image/catalog/menustolovaya/test.jpg'],
        'Картинка из относительного пути разобрана верно'
    );
}

checkCondition(
    findCanteen($russianMenu, '3. Блок без картинок') === null,
    'Блок без картинок пропущен'
);

// --- Английская версия: категории и блюда ---
checkCondition(count($englishMenu['canteens']) === 2, 'В английской версии две столовые с таблицами');

$totalCategories = 0;
$totalDishes = 0;

foreach ($englishMenu['canteens'] as $canteen) {
    $totalCategories += count($canteen['categories']);

    foreach ($canteen['categories'] as $category) {
        $totalDishes += count($category['dishes']);
    }
}

checkCondition($totalCategories === 3, 'Найдены три категории блюд');
checkCondition($totalDishes === 4, 'Битая строка таблицы пропущена, разобраны четыре блюда');

$firstEnglishCanteen = findCanteen($englishMenu, '1. Canteen «Test», Ozheshko 22');
checkCondition($firstEnglishCanteen !== null, 'Название английской столовой очищено от HTML-сущностей');

if ($firstEnglishCanteen !== null) {
    $coldDishes = findCategory($firstEnglishCanteen, 'Cold dishes & snacks');
    checkCondition($coldDishes !== null, 'Категория взята из заголовочной строки таблицы');

    if ($coldDishes !== null) {
        $snowWhite = findDish($coldDishes, 'Salad "Snow White"');
        checkCondition($snowWhite !== null, 'Название блюда с кавычками разобрано');

        if ($snowWhite !== null) {
            checkCondition($snowWhite['ingredients'] === 'salt, sugar, onion', 'Состав блюда очищен от скобок и &nbsp;');
            checkFloat($snowWhite['kcal'], 110.0, 'Ккал блюда разобраны');
            checkCondition($snowWhite['output'] === '100', 'Выход блюда разобран');
            checkFloat($snowWhite['price'], 0.66, 'Цена 0r.66p. превращена в 0.66');
        }

        $pinkSalad = findDish($coldDishes, 'Salad "Pink"');
        checkCondition($pinkSalad !== null, 'Второе блюдо категории найдено');

        if ($pinkSalad !== null) {
            checkFloat($pinkSalad['price'], 1.05, 'Цена 1r.05p. превращена в 1.05');
        }
    }

    $soups = findCategory($firstEnglishCanteen, 'Soups');
    checkCondition($soups !== null, 'Категория взята из заголовка перед таблицей');

    if ($soups !== null) {
        $soup = findDish($soups, 'Potato soup');
        checkCondition($soup !== null, 'Блюдо из второй таблицы найдено');

        if ($soup !== null) {
            checkFloat($soup['price'], 0.34, 'Цена с запятой превращена в 0.34');
            checkCondition($soup['output'] === '250/5', 'Выход вида 250/5 сохранён');
        }

        checkCondition(findDish($soups, 'Broken row') === null, 'Строка из двух ячеек пропущена');
    }
}

$secondEnglishCanteen = findCanteen($englishMenu, '2. Buffet "Test"');
checkCondition($secondEnglishCanteen !== null, 'Вторая столовая найдена');

if ($secondEnglishCanteen !== null) {
    $pastry = findCategory($secondEnglishCanteen, 'Pastry');
    checkCondition($pastry !== null, 'Категория выпечки найдена');

    if ($pastry !== null) {
        $bun = findDish($pastry, 'Bun');
        checkCondition($bun !== null, 'Булочка найдена');

        if ($bun !== null) {
            checkCondition($bun['kcal'] === null, 'Пустая ккал сохранена как null');
            checkFloat($bun['price'], 0.30, 'Цена булочки разобрана');
        }
    }
}

// --- Нормализация цены и текста ---
checkFloat(normalizePrice('0r.66p.'), 0.66, 'normalizePrice: 0r.66p.');
checkFloat(normalizePrice('1r.05p.'), 1.05, 'normalizePrice: 1r.05p.');
checkFloat(normalizePrice('0,34'), 0.34, 'normalizePrice: запятая');
checkFloat(normalizePrice('12'), 12.0, 'normalizePrice: обычное число');
checkCondition(normalizePrice('') === null, 'normalizePrice: пустая строка');
checkCondition(normalizePrice('нет цены') === null, 'normalizePrice: нечисловой текст');
checkCondition(
    normalizeText("  Salad&nbsp;&laquo;Test&raquo;\n ") === 'Salad «Test»',
    'normalizeText: сущности и лишние пробелы'
);

if ($failures !== []) {
    echo PHP_EOL . 'Провалено проверок: ' . count($failures) . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'Все проверки пройдены.' . PHP_EOL;
exit(0);
