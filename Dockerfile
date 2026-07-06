FROM php:8.2-cli-alpine

RUN apk add --no-cache git unzip bash libzip-dev nodejs npm \
    && docker-php-ext-install -j$(nproc) zip

RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS

RUN npm install -g pnpm@10

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Avoid "dubious ownership" when Composer runs git against the mounted repo
RUN git config --global --add safe.directory /app

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
