FROM composer:2 AS dependencies
WORKDIR /app
COPY composer.json composer.lock ./
COPY src ./src
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts
FROM php:8.3-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=dependencies /app/vendor ./vendor
COPY composer.json ./
COPY src ./src
COPY index.php ./
RUN php -r 'require "vendor/autoload.php";' \
    && mkdir -p /var/lib/oci-provisioner \
    && chown 10001:10001 /var/lib/oci-provisioner
ENV OCI_CONTAINER_MODE=1 DRY_RUN=true
USER 10001:10001
WORKDIR /var/lib/oci-provisioner
CMD ["php", "/app/index.php"]
