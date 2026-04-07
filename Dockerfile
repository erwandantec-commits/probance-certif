FROM php:8.2-apache
RUN apt-get update \
  && apt-get install -y --no-install-recommends libzip-dev \
  && docker-php-ext-install pdo pdo_mysql zip \
  && rm -rf /var/lib/apt/lists/*
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY scripts/new-migration.sh /opt/certif/scripts/new-migration.sh
RUN chmod +x /opt/certif/scripts/new-migration.sh
