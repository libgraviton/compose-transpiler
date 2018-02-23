FROM php:7.2-alpine

ADD / /app/

RUN apk update && \
    apk upgrade && \
    # install app
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    cd /app && \
    composer install --no-dev --no-interaction --no-progress && \
    composer dump-autoload --optimize --no-dev --classmap-authoritative && \
    composer clear-cache && \
    chmod +x /app/bin/app
