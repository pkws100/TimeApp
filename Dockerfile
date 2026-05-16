FROM php:8.2-apache

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
        libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install dom \
    && docker-php-ext-install \
        simplexml \
        xml \
        xmlreader \
        xmlwriter \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        fileinfo \
        gd \
        mbstring \
        pdo_mysql \
        zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/zz-zeiterfassung.ini
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint

COPY composer.json composer.lock /var/www/html/

RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-autoloader \
    && chmod +x /usr/local/bin/app-entrypoint

COPY . /var/www/html

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p \
        storage/app/uploads \
        storage/cache/backups \
        storage/cache/exports \
        storage/cache/sessions \
        storage/config \
        storage/logs \
    && chown -R www-data:www-data storage

ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
