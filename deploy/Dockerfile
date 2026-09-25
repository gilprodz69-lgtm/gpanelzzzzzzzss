FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libsodium-dev libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql sodium mbstring curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/panel
COPY . /var/www/panel
RUN mkdir -p storage/logs storage/cache storage/backups \
    && chown -R www-data:www-data storage \
    && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nupload_max_filesize=2M\npost_max_size=2M\n' > /usr/local/etc/php/conf.d/panel.ini
USER www-data
CMD ["php-fpm", "-F"]
