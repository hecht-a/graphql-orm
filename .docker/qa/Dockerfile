# PHP and Composer to run the tests and the integration tests of the bundle, built by the qa Castor tasks:
#   docker build --build-arg PHP_VERSION=8.4 -t graphql-orm-qa:php8.4 .docker/qa
ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-cli

COPY --from=composer:2.9 /usr/bin/composer /usr/local/bin/composer
COPY --from=mlocati/php-extension-installer:2.9 /usr/bin/install-php-extensions /usr/local/bin/

# pcov: the code coverage Infection measures the tests with, disabled unless asked (pcov.enabled=1)
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pcov \
    && echo 'pcov.enabled=0' > "$PHP_INI_DIR/conf.d/zz-pcov.ini" \
    # The 128 MB of the image are short for the code coverage of the whole suite
    && echo 'memory_limit=1G' > "$PHP_INI_DIR/conf.d/zz-memory.ini"

# The container runs as the user of the host: Composer and PHPUnit write under /tmp.
ENV COMPOSER_HOME=/tmp/composer HOME=/tmp

WORKDIR /app
