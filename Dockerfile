# PHP 8.2 Alpine for development and tests
FROM php:8.2-cli-alpine

RUN apk add --no-cache \
    git \
    unzip \
    bash \
    icu-libs \
    libzip-dev \
    postgresql-dev

# intl: Twig filter formatting; pdo_mysql / pdo_pgsql: Database suite on MySQL, MariaDB and PostgreSQL (make test-with-db)
RUN apk add --no-cache --virtual .intl-build icu-dev \
    && docker-php-ext-install -j2 intl zip pdo_mysql pdo_pgsql \
    && apk del .intl-build

# PCOV for code coverage
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN git config --global --add safe.directory /app

# Full ICU data: locale formatting of the Twig output in every supported locale
RUN apk add --no-cache icu-data-full

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
