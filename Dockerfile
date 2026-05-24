FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    sqlite sqlite-dev \
    libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev oniguruma-dev \
    caddy \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql gd mbstring

WORKDIR /var/www/twitkey
COPY . .

RUN mkdir -p /data/avatars /data/uploads /data/cache && chmod -R 777 /data
RUN { \
    echo "upload_max_filesize=64M"; \
    echo "post_max_size=72M"; \
    echo "max_file_uploads=8"; \
} > /usr/local/etc/php/conf.d/twitkey-uploads.ini

EXPOSE 80 443
CMD ["sh", "-c", "php-fpm -D && caddy run --config /var/www/twitkey/Caddyfile --adapter caddyfile"]
