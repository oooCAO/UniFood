# Образ для ежедневного импорта меню market.grsu.by.
# Здесь не нужен Apache: контейнер только запускает cron и PHP-скрипт из bin/.
# Поэтому база — php:8.3-cli, а не образ веб-сервера из docker/php/Dockerfile.

FROM php:8.3-cli

WORKDIR /var/www/html

# pdo_mysql нужен для записи импортированных блюд в каталог (после этапа 2).
# cron запускает задания по расписанию, tzdata — чтобы время было по Минску,
# а не по UTC. Лишние пакеты не ставим.
RUN apt-get update \
    && apt-get install -y --no-install-recommends cron tzdata \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Часовой пояс контейнера: cron читает /etc/localtime, поэтому 07:00 в crontab —
# это 07:00 по Минску. TZ оставляем в окружении, чтобы его видели и PHP-скрипты.
ENV TZ=Europe/Minsk
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# PHP CLI по умолчанию считает время по UTC, а не по часовому поясу системы.
# Время нужно скрипту импорта для даты меню, поэтому задаём пояс явно.
RUN echo 'date.timezone = Europe/Minsk' > /usr/local/etc/php/conf.d/timezone.ini

# Файл в /etc/cron.d cron читает автоматически. Формат отличается от `crontab -e`:
# после расписания нужно указать пользователя (root).
COPY docker/cron/crontab /etc/cron.d/unifood-import
RUN chmod 0644 /etc/cron.d/unifood-import

# cron -f оставляет демон на переднем плане: контейнер не завершается,
# а вывод заданий уходит в лог контейнера.
CMD ["cron", "-f"]
