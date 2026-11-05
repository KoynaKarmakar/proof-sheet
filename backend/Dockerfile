# PHP API for Railway. Railway injects $PORT.
FROM php:8.3-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libcurl4-openssl-dev unzip poppler-utils \
 && docker-php-ext-install zip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json ./
COPY src ./src
COPY public ./public
COPY tests/fixtures ./tests/fixtures
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Built-in server with several workers so one long extraction doesn't block other requests.
ENV PHP_CLI_SERVER_WORKERS=4
CMD php -d upload_max_filesize=25M -d post_max_size=26M -d memory_limit=512M \
        -d max_execution_time=400 -S 0.0.0.0:${PORT:-8080} -t public public/index.php
