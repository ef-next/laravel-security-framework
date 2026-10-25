# PHP_VERSION is the matrix dimension: 8.3 (default), 8.4, 8.5.
#   PHP_VERSION=8.4 ./start-security-framework.sh check
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-alpine

# pcov is only needed for coverage; a PHP release it does not support yet must not break the image.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev linux-headers \
    && apk add --no-cache postgresql-libs git unzip bash \
    && docker-php-ext-install pdo_pgsql pdo_mysql pcntl \
    && (pecl install pcov && docker-php-ext-enable pcov || echo "pcov unavailable for this PHP version; coverage disabled") \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_MEMORY_LIMIT=1G

RUN printf "memory_limit=1G\npcov.enabled=0\n" > /usr/local/etc/php/conf.d/zz-framework.ini

WORKDIR /app
