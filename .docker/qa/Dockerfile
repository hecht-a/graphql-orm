# PHP and Composer to run the tests and the integration tests of the bundle, built by the qa Castor tasks:
#   docker build --build-arg PHP_VERSION=8.4 -t graphql-orm-qa:php8.4 .docker/qa
ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-cli

COPY --from=composer:2.9 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# The container runs as the user of the host: Composer and PHPUnit write under /tmp.
ENV COMPOSER_HOME=/tmp/composer HOME=/tmp

WORKDIR /app
