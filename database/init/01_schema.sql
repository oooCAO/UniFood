-- Схема базы данных UniFood.
-- Выполняется один раз при первом запуске контейнера MySQL (пустой /var/lib/mysql).
-- ENGINE=InnoDB везде обязательно: оформление заказа идёт в транзакции
-- (orders + order_items создаются вместе, иначе откат не сработает).

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('student', 'staff', 'admin') NOT NULL DEFAULT 'student',
    -- Заведение, в котором работает сотрудник (роль staff). У студента и
    -- администратора всегда NULL. FK на venues добавлен ниже отдельным
    -- ALTER TABLE, так как таблица venues создаётся позже в этом файле.
    venue_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE venues (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NOT NULL,
    -- Показывать ли заведение студенту при выборе места получения.
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    -- Порядок показа категорий в меню, не время создания.
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (venue_id) REFERENCES venues(id)
) ENGINE=InnoDB;

CREATE TABLE dishes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- venue_id хранится отдельно от category_id (хотя и выводится из неё),
    -- чтобы проверка "блюдо из этого заведения" была одним условием без JOIN.
    -- При смене category_id на другое заведение venue_id нужно обновлять вручную.
    venue_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10, 2) NOT NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    -- manual — блюдо завёл администратор, market — пришло из импорта меню
    -- с market.grsu.by. Импорт никогда не трогает manual-блюда.
    source ENUM('manual', 'market') NOT NULL DEFAULT 'manual',
    -- Ключ блюда с внешнего сайта (хеш заведения+категории+названия), нужен
    -- для повторного импорта: по нему находим "то же" блюдо, а не создаём
    -- дубль. NULL у ручных блюд, уникальный ключ допускает много NULL.
    external_key VARCHAR(191) NULL,
    -- Русское название заполняет администратор вручную (сайт отдаёт меню
    -- этой столовой только по-английски). Импорт его не перезаписывает.
    name_ru VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (venue_id) REFERENCES venues(id),
    FOREIGN KEY (category_id) REFERENCES categories(id),
    UNIQUE KEY uq_dishes_external_key (external_key)
) ENGINE=InnoDB;

CREATE TABLE pickup_slots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue_id INT UNSIGNED NOT NULL,
    slot_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    -- Максимум заказов на этот слот. Сколько уже занято — считаем COUNT(*)
    -- по orders.slot_id, отдельный счётчик не держим, чтобы не расходился с фактом.
    capacity INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (venue_id) REFERENCES venues(id)
) ENGINE=InnoDB;

CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    venue_id INT UNSIGNED NOT NULL,
    slot_id INT UNSIGNED NOT NULL,
    -- new -> confirmed -> ready -> completed; cancelled — отдельный исход.
    -- Статус меняет только сотрудник, у студента отмены нет (не входит в требования).
    status ENUM('new', 'confirmed', 'ready', 'completed', 'cancelled') NOT NULL DEFAULT 'new',
    -- Сумма заказа на момент оформления, не зависит от текущих цен в dishes.
    total_price DECIMAL(10, 2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (venue_id) REFERENCES venues(id),
    FOREIGN KEY (slot_id) REFERENCES pickup_slots(id)
) ENGINE=InnoDB;

CREATE TABLE order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    dish_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    -- Цена блюда на момент заказа: если цену в dishes поменяют позже,
    -- старые заказы не должны "переехать" на новую сумму.
    price DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (dish_id) REFERENCES dishes(id)
) ENGINE=InnoDB;

-- users.venue_id ссылается на venues, которая создана выше, но после users —
-- добавляем ограничение здесь, а не меняем порядок создания таблиц.
ALTER TABLE users ADD CONSTRAINT fk_users_venue FOREIGN KEY (venue_id) REFERENCES venues(id);

-- Сопоставление названия столовой с market.grsu.by с нашим venue_id.
-- source_name — ровно та строка, которую парсер достаёт из английской
-- таблицы (summary), поэтому уникальная: на одно название — одно заведение.
CREATE TABLE menu_sources (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue_id INT UNSIGNED NOT NULL,
    source_name VARCHAR(150) NOT NULL UNIQUE,
    -- Позволяет временно отключить источник без удаления строки сопоставления.
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (venue_id) REFERENCES venues(id)
) ENGINE=InnoDB;

-- Журнал запусков импорта меню: когда запускался, что сделал, с какой ошибкой
-- (если была). Нужен для страницы «Импорт меню» и для отладки.
CREATE TABLE menu_import_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
    dishes_added INT UNSIGNED NOT NULL DEFAULT 0,
    dishes_updated INT UNSIGNED NOT NULL DEFAULT 0,
    dishes_hidden INT UNSIGNED NOT NULL DEFAULT 0,
    error_text TEXT NULL
) ENGINE=InnoDB;
