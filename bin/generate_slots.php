<?php
/**
 * CLI-скрипт генерации временных слотов получения заказа.
 *
 * По решению заказчика (П.8) слоты держатся только на "сегодня" — без
 * ежедневного запуска этого скрипта на следующий день после
 * docker compose up сайт нельзя проверить, а после 17:00 заказы не
 * оформляются. Запускается вручную (кнопка в админке дёргает
 * generateSlotsForDate() напрямую) и по cron (контракт зафиксирован
 * с Разработчиком 3, протокол C2): крон вызывает именно этот скрипт.
 *
 * Запуск:
 *   php bin/generate_slots.php                      Слоты на сегодня
 *   php bin/generate_slots.php --date=2026-12-01     Слоты на конкретный день
 *   php bin/generate_slots.php --dry-run             Показать, что будет создано, без записи в БД
 *   php bin/generate_slots.php --help                Показать подсказку
 */

require_once __DIR__ . '/../src/pickup_slots.php';

// Скрипт запускается только из консоли (cron, docker compose exec):
// из браузера он не должен быть доступен.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

/**
 * Выводит подсказку по запуску скрипта.
 *
 * @return void
 */
function printGenerateSlotsUsage(): void
{
    echo "Использование:\n";
    echo "  php bin/generate_slots.php                     Создать слоты на сегодня\n";
    echo "  php bin/generate_slots.php --date=YYYY-MM-DD    Создать слоты на указанную дату\n";
    echo "  php bin/generate_slots.php --dry-run            Показать результат без записи в БД\n";
    echo "  php bin/generate_slots.php --help               Показать эту подсказку\n";
}

$arguments = array_slice($argv, 1);

$dryRun = false;
$date = date('Y-m-d');
$error = null;

foreach ($arguments as $argument) {
    if ($argument === '--help') {
        printGenerateSlotsUsage();
        exit(0);
    }

    if ($argument === '--dry-run') {
        $dryRun = true;
        continue;
    }

    if (str_starts_with($argument, '--date=')) {
        $date = substr($argument, strlen('--date='));

        // Проверяем формат и то, что дата реально существует (например,
        // отсекаем 2026-02-30), а не только внешний вид строки.
        $dateParts = [];

        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dateParts) !== 1
            || !checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1])
        ) {
            $error = "Некорректная дата: {$date}. Ожидается формат YYYY-MM-DD.";
        }

        continue;
    }

    $error = "Неизвестный параметр: {$argument}";
}

if ($error !== null) {
    fwrite(STDERR, $error . PHP_EOL);
    printGenerateSlotsUsage();
    exit(1);
}

try {
    if ($dryRun) {
        // Пробный запуск: считаем результат в транзакции и откатываем её,
        // чтобы ничего не осталось в БД (тот же приём, что у runMenuImport()).
        $pdo = getMysqlConnection();
        $pdo->beginTransaction();
        $result = generateSlotsForDate($date);
        $pdo->rollBack();

        echo "Пробный запуск на дату {$date} (в БД ничего не записано):\n";
    } else {
        $result = generateSlotsForDate($date);

        echo "Слоты на дату {$date}:\n";
    }

    echo "Создано: {$result['created']}\n";
    echo "Пропущено: {$result['skipped']}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Не удалось сгенерировать слоты: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

exit(0);
