<?php
/**
 * CLI-скрипт удаления QA-артефактов из базы (П.6).
 *
 * ПЕРЕД ЗАПУСКОМ СДЕЛАЙТЕ ДАМП БАЗЫ — удаление необратимо:
 *   docker compose exec -T mysql mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" \
 *     "$MYSQL_DATABASE" > unifood_before_cleanup.sql
 *
 * Скрипт удаляет только тестовые артефакты, оставленные QA-прогоном:
 *   - заведение «QA Кафе Тест 2» (категории, блюда, слоты, заказы);
 *   - пользователей с email вида qa.%@...;
 *   - блюдо с XSS в названии (name LIKE '%<script%');
 *   - заказы QA-пользователей и заказы на QA-заведение (и их историю в Mongo).
 * Демо-аккаунты (student@/staff@/admin@unifood.test), два реальных заведения
 * и блюда source = 'market' не трогает.
 *
 * Запуск:
 *   php bin/cleanup_test_data.php             Пробный запуск (по умолчанию),
 *                                             ничего не удаляет
 *   php bin/cleanup_test_data.php --dry-run   То же самое явно
 *   php bin/cleanup_test_data.php --confirm   Настоящее удаление
 *   php bin/cleanup_test_data.php --help      Показать подсказку
 */

require_once __DIR__ . '/../src/db_mysql.php';
require_once __DIR__ . '/../src/db_mongo.php';

// Скрипт запускается только из консоли: случайный заход из браузера
// не должен даже пытаться что-то удалить.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

/**
 * Выводит подсказку по запуску скрипта.
 *
 * @return void
 */
function printCleanupUsage(): void
{
    echo "Использование:\n";
    echo "  php bin/cleanup_test_data.php             Пробный запуск (по умолчанию), ничего не удаляет\n";
    echo "  php bin/cleanup_test_data.php --dry-run   То же самое явно\n";
    echo "  php bin/cleanup_test_data.php --confirm   Настоящее удаление\n";
    echo "  php bin/cleanup_test_data.php --help      Показать эту подсказку\n";
}

/**
 * Находит всё, что попадает под QA-артефакты.
 *
 * Отдельная функция, а не инлайн-код: одни и те же данные нужны и для
 * пробного запуска (просто показать), и для настоящего (что удалять).
 *
 * @param PDO $pdo Подключение к MySQL.
 * @return array{
 *   venueId: int|null,
 *   venueName: string|null,
 *   qaUserIds: array<int, array{id: int, email: string}>,
 *   venueStaffIds: array<int, array{id: int, email: string}>,
 *   xssDishes: array<int, array{id: int, name: string}>,
 *   orderIds: array<int, int>
 * } Найденные артефакты.
 */
function findQaArtifacts(PDO $pdo): array
{
    // Заведение, которое QA создавали специально для своих проверок.
    $venueStmt = $pdo->prepare("SELECT id, name FROM venues WHERE name = 'QA Кафе Тест 2'");
    $venueStmt->execute();
    $venue = $venueStmt->fetch();

    $venueId = $venue !== false ? (int) $venue['id'] : null;
    $venueName = $venue !== false ? $venue['name'] : null;

    // Пользователи QA: по шаблону email, который использовали тестировщики.
    $qaUsersStmt = $pdo->query("SELECT id, email FROM users WHERE email LIKE 'qa.%@%'");
    $qaUserIds = $qaUsersStmt->fetchAll();

    // Сотрудники, привязанные к QA-заведению, но без qa.-почты (например,
    // если тестировщик завёл сотрудника вручную под другим адресом) —
    // их users.venue_id всё равно ссылается на QA-заведение и не даст
    // его удалить, пока не обнулим ссылку.
    $venueStaffIds = [];

    if ($venueId !== null) {
        $staffStmt = $pdo->prepare('SELECT id, email FROM users WHERE venue_id = ?');
        $staffStmt->execute([$venueId]);
        $venueStaffIds = $staffStmt->fetchAll();
    }

    // Блюдо с XSS в названии — ищем везде, не только в QA-заведении:
    // тестировщик мог вставить его и в реальное заведение по ошибке.
    $xssStmt = $pdo->query("SELECT id, name FROM dishes WHERE name LIKE '%<script%'");
    $xssDishes = $xssStmt->fetchAll();

    // Заказы на удаление: заказы QA-пользователей (по id, включая
    // "сотрудников без qa.-почты") и заказы на QA-заведение.
    $userIds = array_unique(array_merge(
        array_column($qaUserIds, 'id'),
        array_column($venueStaffIds, 'id')
    ));

    $orderIds = [];

    if ($userIds !== [] || $venueId !== null) {
        $conditions = [];
        $params = [];

        if ($userIds !== []) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $conditions[] = "user_id IN ({$placeholders})";
            $params = array_merge($params, $userIds);
        }

        if ($venueId !== null) {
            $conditions[] = 'venue_id = ?';
            $params[] = $venueId;
        }

        $ordersStmt = $pdo->prepare(
            'SELECT id FROM orders WHERE ' . implode(' OR ', $conditions)
        );
        $ordersStmt->execute($params);
        $orderIds = array_map('intval', $ordersStmt->fetchAll(PDO::FETCH_COLUMN));
    }

    return [
        'venueId' => $venueId,
        'venueName' => $venueName,
        'qaUserIds' => $qaUserIds,
        'venueStaffIds' => $venueStaffIds,
        'xssDishes' => $xssDishes,
        'orderIds' => $orderIds,
    ];
}

/**
 * Печатает, что нашлось (для пробного и настоящего запуска одинаково).
 *
 * @param array $artifacts Результат findQaArtifacts().
 * @return void
 */
function printArtifacts(array $artifacts): void
{
    if ($artifacts['venueId'] !== null) {
        echo "  заведение: \"{$artifacts['venueName']}\" (id {$artifacts['venueId']})\n";
    } else {
        echo "  заведение \"QA Кафе Тест 2\": не найдено\n";
    }

    if ($artifacts['qaUserIds'] !== []) {
        echo '  пользователи qa.*@: ' . implode(', ', array_column($artifacts['qaUserIds'], 'email')) . "\n";
    } else {
        echo "  пользователи qa.*@: не найдены\n";
    }

    $extraStaff = array_udiff(
        $artifacts['venueStaffIds'],
        $artifacts['qaUserIds'],
        fn (array $a, array $b) => $a['id'] <=> $b['id']
    );

    if ($extraStaff !== []) {
        echo '  сотрудники QA-заведения без qa.-почты: '
            . implode(', ', array_column($extraStaff, 'email')) . "\n";
    }

    if ($artifacts['xssDishes'] !== []) {
        foreach ($artifacts['xssDishes'] as $dish) {
            echo "  XSS-блюдо: id {$dish['id']}, название: {$dish['name']}\n";
        }
    } else {
        echo "  блюдо с XSS в названии: не найдено\n";
    }

    echo '  заказов на удаление: ' . count($artifacts['orderIds']);

    if ($artifacts['orderIds'] !== []) {
        echo ' (id ' . implode(', ', $artifacts['orderIds']) . ')';
    }

    echo "\n";
}

/**
 * Проверяет, есть ли хоть что-то для удаления.
 *
 * @param array $artifacts Результат findQaArtifacts().
 * @return bool true, если нашёлся хотя бы один артефакт.
 */
function hasAnyArtifacts(array $artifacts): bool
{
    return $artifacts['venueId'] !== null
        || $artifacts['qaUserIds'] !== []
        || $artifacts['venueStaffIds'] !== []
        || $artifacts['xssDishes'] !== []
        || $artifacts['orderIds'] !== [];
}

/**
 * Удаляет найденные QA-артефакты из MySQL и MongoDB.
 *
 * Порядок продиктован внешними ключами: сначала история в Mongo и
 * order_items/orders (иначе нельзя будет удалить пользователей и
 * заведение, на которые они ссылаются), затем пользователи, блюда,
 * категории и слоты, и только в конце само заведение. Пользователей,
 * у которых остался хотя бы один заказ (не входящий в список на
 * удаление), не трогаем — это не наш случай, но проверяем явно, а не
 * предполагаем.
 *
 * @param PDO $pdo Подключение к MySQL.
 * @param array $artifacts Результат findQaArtifacts().
 * @return void
 * @throws Throwable Если Mongo недоступна или SQL упал — вызывающий код
 *   решает, что делать (здесь всегда прерывает скрипт).
 */
function deleteQaArtifacts(PDO $pdo, array $artifacts): void
{
    $orderIds = $artifacts['orderIds'];

    // 1. Mongo — до начала транзакции MySQL. Если Mongo недоступна,
    // исключение уйдёт вызывающему коду, и ни одна строка в MySQL
    // не будет тронута.
    if ($orderIds !== []) {
        getMongoDatabase()->selectCollection('order_status_history')->deleteMany([
            'order_id' => ['$in' => $orderIds],
        ]);
    }

    $pdo->beginTransaction();

    try {
        // 2-3. order_items и orders этих заказов.
        if ($orderIds !== []) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $pdo->prepare("DELETE FROM order_items WHERE order_id IN ({$placeholders})")->execute($orderIds);
            $pdo->prepare("DELETE FROM orders WHERE id IN ({$placeholders})")->execute($orderIds);
        }

        // 4 и 8 вместе: все пользователи-кандидаты (qa.*@ и сотрудники
        // QA-заведения) — обнуляем venue_id (FK на venues не даст удалить
        // заведение, пока на него ссылается хоть один пользователь), затем
        // удаляем тех, у кого не осталось заказов. Обнуление выполняем
        // раньше удаления заведения (шаг 7), поэтому технически объединяем
        // с шагом 8 здесь, а не в конце.
        // array_values() обязателен: array_unique() сохраняет исходные ключи
        // массива, и если один и тот же id встретился в обоих списках (например,
        // qa.staff@ одновременно привязан к QA-заведению), после удаления
        // дубля в ключах образуется "дырка" — PDO::execute() с позиционными
        // "?" требует последовательные ключи 0,1,2..., иначе падает с
        // "Invalid parameter number" (поймано при проверке в Docker).
        $candidateIds = array_values(array_unique(array_merge(
            array_column($artifacts['qaUserIds'], 'id'),
            array_column($artifacts['venueStaffIds'], 'id')
        )));

        if ($candidateIds !== []) {
            $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
            $pdo->prepare("UPDATE users SET venue_id = NULL WHERE id IN ({$placeholders})")->execute($candidateIds);

            // Удаляем только тех, у кого реально не осталось заказов —
            // не предполагаем, а проверяем, иначе DELETE упадёт на FK orders.user_id.
            $remainingOrdersStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM orders WHERE user_id IN ({$placeholders})"
            );
            $remainingOrdersStmt->execute($candidateIds);

            if ((int) $remainingOrdersStmt->fetchColumn() === 0) {
                $pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})")->execute($candidateIds);
            }
        }

        // 5. Блюдо с XSS (может быть и не в QA-заведении, поэтому отдельно
        // от удаления блюд QA-заведения ниже).
        $dishIds = array_column($artifacts['xssDishes'], 'id');

        if ($dishIds !== []) {
            $placeholders = implode(',', array_fill(0, count($dishIds), '?'));
            $pdo->prepare("DELETE FROM dishes WHERE id IN ({$placeholders})")->execute($dishIds);
        }

        if ($artifacts['venueId'] !== null) {
            $pdo->prepare('DELETE FROM dishes WHERE venue_id = ?')->execute([$artifacts['venueId']]);

            // 6. Категории и слоты QA-заведения.
            $pdo->prepare('DELETE FROM categories WHERE venue_id = ?')->execute([$artifacts['venueId']]);
            $pdo->prepare('DELETE FROM pickup_slots WHERE venue_id = ?')->execute([$artifacts['venueId']]);

            // 7. Само заведение — последним, когда на него уже никто не ссылается.
            $pdo->prepare('DELETE FROM venues WHERE id = ?')->execute([$artifacts['venueId']]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

$arguments = array_slice($argv, 1);
$knownArguments = ['--dry-run', '--confirm', '--help'];
$unknownArguments = array_diff($arguments, $knownArguments);

if ($unknownArguments !== []) {
    fwrite(STDERR, 'Неизвестные параметры: ' . implode(', ', $unknownArguments) . PHP_EOL);
    printCleanupUsage();
    exit(1);
}

if (in_array('--help', $arguments, true)) {
    printCleanupUsage();
    exit(0);
}

if (in_array('--dry-run', $arguments, true) && in_array('--confirm', $arguments, true)) {
    fwrite(STDERR, '--dry-run и --confirm вместе не имеют смысла, выберите один.' . PHP_EOL);
    exit(1);
}

$confirm = in_array('--confirm', $arguments, true);

try {
    $pdo = getMysqlConnection();
    $artifacts = findQaArtifacts($pdo);

    if (!hasAnyArtifacts($artifacts)) {
        echo "Нечего удалять: QA-артефакты не найдены.\n";
        exit(0);
    }

    if (!$confirm) {
        echo "Пробный запуск (в БД и Mongo ничего не изменено). Найдено:\n";
        printArtifacts($artifacts);
        echo "\nДля настоящего удаления запустите с --confirm.\n";
        exit(0);
    }

    echo "Удаляется:\n";
    printArtifacts($artifacts);

    deleteQaArtifacts($pdo, $artifacts);

    echo "\nУдаление выполнено.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Не удалось выполнить очистку: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

exit(0);
