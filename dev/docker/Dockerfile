# Craft CMS 4/5 test environment for the craft-assetmetadata plugin.
# Based on php:8.2-cli (supported by both Craft 4 and Craft 5) and served by PHP's
# built-in web server through dev/docker/router.php.
FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libonig-dev \
        git unzip ca-certificates default-mysql-client curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd bcmath exif opcache mbstring \
    && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN { \
        echo 'memory_limit=512M'; \
        echo 'max_execution_time=300'; \
        echo 'upload_max_filesize=128M'; \
        echo 'post_max_size=128M'; \
        echo 'max_input_vars=5000'; \
        echo 'display_errors=On'; \
    } > /usr/local/etc/php/conf.d/craft.ini

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PHP_CLI_SERVER_WORKERS=24
WORKDIR /app
COPY entrypoint.sh /usr/local/bin/craft-entrypoint
COPY router.php /usr/local/lib/craft-router.php
RUN chmod +x /usr/local/bin/craft-entrypoint
EXPOSE 80
ENTRYPOINT ["craft-entrypoint"]
CMD ["php", "-S", "0.0.0.0:80", "-t", "/app/web", "/usr/local/lib/craft-router.php"]
