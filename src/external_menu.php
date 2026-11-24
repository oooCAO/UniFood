<?php
/**
 * Загрузка и разбор меню с внешнего сайта market.grsu.by.
 *
 * Русская версия страницы содержит меню картинками, английская — обычными
 * HTML-таблицами. Этот файл только скачивает и разбирает данные, в базу
 * ничего не пишет: запись в каталог появится в отдельной задаче.
 */

// Адреса внешнего сайта вынесены в константы, чтобы не повторять их в коде.
const EXTERNAL_MENU_BASE_URL = 'https://market.grsu.by';
const EXTERNAL_MENU_URL = EXTERNAL_MENU_BASE_URL . '/today-menu';
const EXTERNAL_MENU_LANGUAGE_URL = EXTERNAL_MENU_BASE_URL . '/index.php?route=common/language/language';

// Представляемся понятным именем, чтобы администратор сайта видел, кто его нагружает.
const EXTERNAL_MENU_USER_AGENT = 'UniFoodMenuParser/1.0 (+https://github.com/oooCAO/UniFood; educational project)';

/**
 * Скачивает страницу внешнего сайта через cURL.
 *
 * @param string $url Адрес страницы.
 * @param string $acceptLanguage Значение заголовка Accept-Language.
 * @param string|null $cookieJar Путь к файлу cookie, если нужна сессия сайта.
 * @param array<string, string>|null $postFields Поля POST-запроса или null для GET.
 * @return string HTML страницы.
 * @throws RuntimeException Если запрос не выполнился или сервер вернул ошибку.
 */
function fetchExternalPage(
    string $url,
    string $acceptLanguage = 'en-US,en;q=0.9',
    ?string $cookieJar = null,
    ?array $postFields = null
): string {
    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('Не удалось инициализировать cURL.');
    }

    $headers = ['Accept-Language: ' . $acceptLanguage];

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        // Долго ждать внешний сайт нельзя: CLI-скрипт не должен висеть вечно.
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => EXTERNAL_MENU_USER_AGENT,
        CURLOPT_HTTPHEADER => $headers,
        // Пустая строка включает автоматическое распаковывание gzip.
        CURLOPT_ENCODING => '',
    ]);

    if ($cookieJar !== null) {
        curl_setopt($curl, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($curl, CURLOPT_COOKIEFILE, $cookieJar);
    }

    if ($postFields !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }

    $body = curl_exec($curl);
    $error = curl_error($curl);
    $errorNumber = curl_errno($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    curl_close($curl);

    if ($body === false) {
        throw new RuntimeException(
            sprintf('Не удалось загрузить %s: %s (код %d).', $url, $error, $errorNumber)
        );
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException(
            sprintf('Сайт вернул HTTP-код %d для %s.', $statusCode, $url)
        );
    }

    if (trim($body) === '') {
        throw new RuntimeException('Сайт вернул пустую страницу: ' . $url);
    }

    return $body;
}

/**
 * Загружает русскую версию страницы «Меню на сегодня».
 *
 * Язык переключается POST-запросом, поэтому сначала получаем cookie сайта,
 * а потом отправляем форму переключения на ru-ru. Без cookie язык может
 * не сохраниться.
 *
 * @return string HTML русской страницы.
 * @throws RuntimeException Если сайт недоступен.
 */
function fetchRussianMenuHtml(): string
{
    $cookieJar = @tempnam(sys_get_temp_dir(), 'unifood_menu_');

    if ($cookieJar === false) {
        throw new RuntimeException('Не удалось создать временный файл для cookie.');
    }

    try {
        // Первый запрос нужен только для получения cookie сессии.
        fetchExternalPage(EXTERNAL_MENU_URL, 'ru-RU,ru;q=0.9', $cookieJar);

        return fetchExternalPage(
            EXTERNAL_MENU_LANGUAGE_URL,
            'ru-RU,ru;q=0.9',
            $cookieJar,
            [
                'code' => 'ru-ru',
                'redirect' => EXTERNAL_MENU_URL,
            ]
        );
    } finally {
        // Временный файл с cookie после запроса не нужен.
        if (is_file($cookieJar)) {
            unlink($cookieJar);
        }
    }
}

/**
 * Загружает английскую версию страницы «Меню на сегодня».
 *
 * Английский — язык сайта по умолчанию, но без заголовка Accept-Language
 * сайт может отдать русскую версию, поэтому заголовок передаём явно.
 *
 * @return string HTML английской страницы.
 * @throws RuntimeException Если сайт недоступен.
 */
function fetchEnglishMenuHtml(): string
{
    return fetchExternalPage(EXTERNAL_MENU_URL, 'en-US,en;q=0.9');
}

/**
 * Загружает переданный HTML в DOMDocument.
 *
 * Ошибки разбора подавляем: вёрстка внешнего сайта может быть неидеальной,
 * но это не повод падать, если документ в целом прочитался.
 *
 * @param string $html HTML страницы.
 * @return DOMDocument Документ для поиска через XPath.
 * @throws RuntimeException Если HTML пустой или совсем не разобрался.
 */
function loadExternalMenuDocument(string $html): DOMDocument
{
    if (trim($html) === '') {
        throw new RuntimeException('Для разбора передана пустая HTML-страница.');
    }

    $document = new DOMDocument();
    $previousUseErrors = libxml_use_internal_errors(true);

    // Префикс с кодировкой нужен, чтобы DOMDocument не принял UTF-8 за latin1
    // и не испортил русские названия.
    $loaded = $document->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET);

    libxml_clear_errors();
    libxml_use_internal_errors($previousUseErrors);

    if (!$loaded) {
        throw new RuntimeException('Не удалось разобрать HTML внешнего сайта.');
    }

    return $document;
}

/**
 * Возвращает блок #tab-description, в котором лежит всё меню.
 *
 * @param DOMDocument $document Разобранная страница.
 * @param DOMXPath $xpath XPath-объект для этого документа.
 * @return DOMNode Блок с описанием.
 * @throws RuntimeException Если блок не найден (сайт изменил вёрстку).
 */
function getExternalMenuDescription(DOMDocument $document, DOMXPath $xpath): DOMNode
{
    $node = $xpath->query("//div[@id='tab-description']")->item(0);

    if ($node === null) {
        throw new RuntimeException(
            'На странице market.grsu.by не найден блок #tab-description. Возможно, сайт изменил вёрстку.'
        );
    }

    return $node;
}

/**
 * Приводит текст из HTML к обычному виду.
 *
 * Раскрывает HTML-сущности (&nbsp;, &laquo; и т.п.) и убирает лишние
 * пробелы и переносы строк.
 *
 * @param string $text Исходный текст.
 * @return string Очищенный текст.
 */
function normalizeText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // \s в PCRE не всегда считает неразрывный пробел пробелом, заменяем вручную.
    $text = str_replace("\u{00A0}", ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

/**
 * Превращает цену вида «0r.66p.» в число 0.66.
 *
 * Поддерживает также запятую как разделитель и пустые значения.
 *
 * @param string $raw Цена в том виде, как она написана на сайте.
 * @return float|null Цена или null, если цену разобрать не удалось.
 */
function normalizePrice(string $raw): ?float
{
    $value = mb_strtolower(normalizeText($raw));

    if ($value === '') {
        return null;
    }

    // Основной формат сайта: «0r.66p.» или «1r.05p.».
    if (preg_match('/^(\d+)\s*[rр]\s*[.,]\s*(\d+)\s*[pр]\.?$/u', $value, $matches)) {
        return (float) ($matches[1] . '.' . $matches[2]);
    }

    // На всякий случай принимаем и обычное число: «0.66» или «0,66».
    if (preg_match('/^\d+(?:[.,]\d+)?$/u', $value)) {
        return (float) str_replace(',', '.', $value);
    }

    return null;
}

/**
 * Превращает число с запятой в float.
 *
 * @param string $raw Число в текстовом виде, например «212,2».
 * @return float|null Число или null, если это не число.
 */
function normalizeNumber(string $raw): ?float
{
    $value = str_replace(',', '.', normalizeText($raw));

    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    return (float) $value;
}

/**
 * Делает ссылку на картинку абсолютной.
 *
 * @param string $url Ссылка из атрибута src.
 * @return string Абсолютный адрес.
 */
function makeAbsoluteUrl(string $url): string
{
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    return EXTERNAL_MENU_BASE_URL . '/' . ltrim($url, '/');
}

/**
 * Разбирает русскую версию страницы.
 *
 * В русской версии меню лежит картинками, поэтому из каждого details берём
 * название столовой и ссылки на изображения. Блоки без картинок пропускаем.
 *
 * @param string $html HTML русской страницы.
 * @return array{canteens: array<int, array{name: string, images: array<int, string>}>}
 * @throws RuntimeException Если меню не найдено.
 */
function parseRussianMenu(string $html): array
{
    $document = loadExternalMenuDocument($html);
    $xpath = new DOMXPath($document);
    $description = getExternalMenuDescription($document, $xpath);

    $canteens = [];

    foreach ($xpath->query('.//details', $description) as $details) {
        $summary = $xpath->query('.//summary', $details)->item(0);
        $name = $summary !== null ? normalizeText($summary->textContent) : '';

        if ($name === '') {
            continue;
        }

        $images = [];

        foreach ($xpath->query('.//img', $details) as $image) {
            $src = trim($image->getAttribute('src'));

            // Служебная картинка кнопки лежит в той же папке, но меню не является.
            if ($src === '' || !str_contains($src, 'menustolovaya') || str_contains($src, 'menustolovaya/button/')) {
                continue;
            }

            $images[] = makeAbsoluteUrl($src);
        }

        // Меню-картинок нет — блок не нужен для страницы «Меню на сегодня».
        if ($images === []) {
            continue;
        }

        $canteens[] = [
            'name' => $name,
            'images' => array_values(array_unique($images)),
        ];
    }

    if ($canteens === []) {
        throw new RuntimeException('В русской версии не найдено ни одной столовой с картинками меню.');
    }

    return ['canteens' => $canteens];
}

/**
 * Разбирает английскую версию страницы.
 *
 * Английская версия свёрстана таблицами: у каждой столовой есть details,
 * внутри — таблицы по категориям блюд.
 *
 * @param string $html HTML английской страницы.
 * @return array{canteens: array<int, array{name: string, categories: array<int, array{name: string, dishes: array<int, array{name: string, ingredients: string, kcal: float|null, output: string, price: float|null, price_raw: string}>}>}>}
 * @throws RuntimeException Если таблицы с блюдами не найдены.
 */
function parseEnglishMenu(string $html): array
{
    $document = loadExternalMenuDocument($html);
    $xpath = new DOMXPath($document);
    $description = getExternalMenuDescription($document, $xpath);

    $canteens = [];

    foreach ($xpath->query('.//details', $description) as $details) {
        $summary = $xpath->query('.//summary', $details)->item(0);
        $name = $summary !== null ? normalizeText($summary->textContent) : '';

        if ($name === '') {
            continue;
        }

        $categories = [];
        $categoryIndexes = [];
        $currentCategory = '';

        // Идём по странице сверху вниз: заголовок категории стоит перед таблицей.
        // XPath с | возвращает узлы в порядке появления в документе.
        $nodes = $xpath->query(
            './/p[b] | .//table[contains(concat(" ", normalize-space(@class), " "), " table-bordered ")]',
            $details
        );

        foreach ($nodes as $node) {
            if ($node->nodeName === 'p') {
                // Параграфы внутри таблиц — это названия блюд, а не заголовки.
                if ($xpath->query('ancestor::table', $node)->length > 0) {
                    continue;
                }

                $currentCategory = rtrim(normalizeText($node->textContent), ':');
                continue;
            }

            if ($node->nodeName !== 'table') {
                continue;
            }

            $dishes = parseEnglishMenuTable($xpath, $node, $currentCategory);

            foreach ($dishes as $dish) {
                $categoryName = $dish['category'];

                if (!isset($categoryIndexes[$categoryName])) {
                    $categoryIndexes[$categoryName] = count($categories);
                    $categories[] = [
                        'name' => $categoryName,
                        'dishes' => [],
                    ];
                }

                unset($dish['category']);
                $categories[$categoryIndexes[$categoryName]]['dishes'][] = $dish;
            }
        }

        if ($categories === []) {
            continue;
        }

        $canteens[] = [
            'name' => $name,
            'categories' => $categories,
        ];
    }

    if ($canteens === []) {
        throw new RuntimeException(
            'В английской версии не найдено ни одной таблицы с блюдами. Возможно, сайт изменил вёрстку.'
        );
    }

    return ['canteens' => $canteens];
}

/**
 * Разбирает одну таблицу с блюдами.
 *
 * Каждая строка таблицы — блюдо: название с составом, ккал, выход и цена.
 * Первая строка может быть заголовком с названием категории.
 *
 * @param DOMXPath $xpath XPath-объект документа.
 * @param DOMNode $table Таблица с блюдами.
 * @param string $defaultCategory Категория из заголовка перед таблицей.
 * @return array<int, array{category: string, name: string, ingredients: string, kcal: float|null, output: string, price: float|null, price_raw: string}>
 */
function parseEnglishMenuTable(DOMXPath $xpath, DOMNode $table, string $defaultCategory): array
{
    $dishes = [];
    $category = $defaultCategory;

    foreach ($xpath->query('.//tr', $table) as $row) {
        $cells = $xpath->query('./td|./th', $row);

        // В нормальной строке пять колонок. Всё остальное — мусор или служебная строка.
        if ($cells->length < 5) {
            continue;
        }

        $cellTexts = [];

        foreach ($cells as $cell) {
            $cellTexts[] = normalizeText($cell->textContent);
        }

        if (isEnglishMenuHeaderRow($cellTexts)) {
            // В первой таблице категория записана прямо в заголовке.
            if ($cellTexts[0] !== '') {
                $category = rtrim($cellTexts[0], ':');
            }

            continue;
        }

        $dishCell = $cells->item(0);

        if (!$dishCell instanceof DOMElement) {
            continue;
        }

        [$dishName, $ingredients] = extractDishNameAndIngredients($xpath, $dishCell);

        if ($dishName === '') {
            continue;
        }

        $priceRaw = $cellTexts[4];
        $output = $cellTexts[3];

        $dishes[] = [
            'category' => $category !== '' ? $category : 'Без категории',
            'name' => $dishName,
            'ingredients' => $ingredients,
            'kcal' => normalizeNumber($cellTexts[2]),
            'output' => $output,
            'price' => normalizePrice($priceRaw),
            'price_raw' => $priceRaw,
        ];
    }

    return $dishes;
}

/**
 * Проверяет, является ли строка таблицы заголовком с названиями колонок.
 *
 * @param array<int, string> $cellTexts Тексты ячеек строки.
 * @return bool true, если это заголовок.
 */
function isEnglishMenuHeaderRow(array $cellTexts): bool
{
    $rowText = mb_strtolower(implode(' ', $cellTexts));

    return str_contains($rowText, 'kcal')
        || str_contains($rowText, 'dish output')
        || str_contains($rowText, 'price');
}

/**
 * Достаёт из первой ячейки название блюда и состав.
 *
 * На сайте название и состав обычно лежат в отдельных параграфах:
 * «Salad "Snow White"» и «(salt, sugar, onion)».
 *
 * @param DOMXPath $xpath XPath-объект документа.
 * @param DOMElement $cell Первая ячейка строки.
 * @return array{0: string, 1: string} Название и состав.
 */
function extractDishNameAndIngredients(DOMXPath $xpath, DOMElement $cell): array
{
    $texts = [];

    foreach ($xpath->query('.//p', $cell) as $paragraph) {
        $text = normalizeText($paragraph->textContent);

        if ($text !== '') {
            $texts[] = $text;
        }
    }

    if ($texts !== []) {
        $name = array_shift($texts);
        $ingredients = implode(' ', $texts);
    } else {
        $text = normalizeText($cell->textContent);
        $bracketPosition = mb_strpos($text, '(');

        if ($bracketPosition === false) {
            $name = $text;
            $ingredients = '';
        } else {
            $name = trim(mb_substr($text, 0, $bracketPosition));
            $ingredients = trim(mb_substr($text, $bracketPosition));
        }
    }

    // Состав на сайте всегда в скобках, скобки для хранения не нужны.
    if (mb_strlen($ingredients) > 1 && str_starts_with($ingredients, '(') && str_ends_with($ingredients, ')')) {
        $ingredients = trim(mb_substr($ingredients, 1, -1));
    }

    return [normalizeText($name), normalizeText($ingredients)];
}
