# Образ PHP + Apache для проекта UniFood.
# Собирает окружение, в котором работает backend: PHP 8.3, Apache с mod_rewrite,
# драйверы MySQL (PDO) и MongoDB, а также Composer.

FROM php:8.3-apache

# Рабочая папка совпадает с корнем сайта Apache: сюда монтируются public/, src/
# и composer.json, поэтому изменения кода видны без пересборки образа.
WORKDIR /var/www/html

# $PHPIZE_DEPS нужен, чтобы собрать расширение mongodb через pecl (там компилятор,
# phpize и заголовки). pkg-config подключаем отдельно: pecl ищет его при сборке.
# После установки пакеты сборки удаляем, чтобы образ не распухал.
RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS pkg-config \
    && docker-php-ext-install pdo_mysql \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# mod_rewrite нужен для «красивых» адресов вида /menu вместо /menu.php.
# Включаем сразу, хотя правила .htaccess появятся позже.
RUN a2enmod rewrite

# По умолчанию Apache смотрит в /var/www/html, а корень веб-сервера у нас public/.
# Поэтому меняем DocumentRoot в дефолтном конфиге сайта: наружу не должен быть
# виден src/ с подключениями к БД.
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' \
        /etc/apache2/sites-available/000-default.conf

# Разрешаем .htaccess в папке public/. Без AllowOverride Apache будет отдавать
# файлы, но игнорировать правила перезаписи внутри них.
RUN printf '%s\n' \
        '<Directory /var/www/html/public>' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/unifood.conf \
    && a2enconf unifood

# Готовый бинарник Composer берём из официального образа: apt-пакет тянет
# лишние зависимости, а копирование файла — самый предсказуемый способ.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

EXPOSE 80
