# Single-stage image: this API has no build step, only PHP sources plus
# Composer's autoloader.
FROM php:8.2-cli-alpine

# pdo_sqlite and sqlite3 ship enabled in the official image; the sqlite-libs
# package is what the extension links against at runtime.
RUN apk add --no-cache sqlite-libs

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy the manifests first so `composer install` is cached until the
# dependencies themselves change, not on every source edit.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts

COPY . .

# The database and the log live on a volume, not in the image layer.
ENV DB_PATH=/data/finance.sqlite \
    LOG_PATH=/data/logs/app.log \
    APP_DEBUG=false

RUN mkdir -p /data/logs && chown -R www-data:www-data /data /app

USER www-data

EXPOSE 8000

# Migrate before serving: the container comes up with a schema that matches
# the code it is running.
CMD ["sh", "-c", "php bin/migrate.php && php -S 0.0.0.0:8000 -t public"]
