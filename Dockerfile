# syntax=docker/dockerfile:1.2

#    # Build application front-end (you can drop this block at all if you want)
#    FROM node:17.4-alpine as frontend
#    # copy all application sources
#    COPY . /app/
#    # use directory with application sources by default
#    WORKDIR /app
#    # build frontend
#    RUN set -x \
#        && yarn install --frozen-lockfile --no-progress --non-interactive \
#        && NODE_ENV="production" yarn run prod

# fetch the Composer image, image page: <https://hub.docker.com/_/composer>
FROM composer:2.4.4 as composer

# build application runtime, image page: <https://hub.docker.com/_/php>
FROM php:8.1.12-fpm-alpine as runtime

ARG PHP_CONTAINER=php-fpm
ARG PHP_PORT=9000

RUN set -x \
    # install permanent dependencies
    && apk add --no-cache \
        postgresql-libs \
        mariadb-client \
        icu-libs \
        nginx \
    # install build-time dependencies
    && apk add --no-cache --virtual .build-deps \
        postgresql-dev \
        autoconf \
        openssl \
        make \
        g++

RUN set -x \
    # install PHP extensions (CFLAGS usage reason - https://bit.ly/3ALS5NU)
    && CFLAGS="$CFLAGS -D_GNU_SOURCE" docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        sockets \
        opcache \
        pcntl \
        intl \
    && pecl install -o redis 1>/dev/null \
    && echo 'extension=redis.so' > ${PHP_INI_DIR}/conf.d/redis.ini \
    # install xdebug extension (but do not enable it; only enable at runtime, as needed)
    && pecl install -o xdebug 1>/dev/null \
    # enable opcache for CLI and JIT, docs: <https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.jit>
    && echo -e "\nopcache.enable=1\nopcache.enable_cli=1\nopcache.jit_buffer_size=32M\nopcache.jit=1235\n" >> \
        ${PHP_INI_DIR}/conf.d/docker-php-ext-opcache.ini \
    # provide the ability to check the health of php-fpm, docs: <https://github.com/renatomefi/php-fpm-healthcheck>
    && wget -O /usr/local/bin/php-fpm-healthcheck \
       https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck \
       && chmod +x /usr/local/bin/php-fpm-healthcheck \
    # show installed PHP modules
    && php -m \
    # install supercronic (for laravel task scheduling), project page: <https://github.com/aptible/supercronic>
    && wget -q "https://github.com/aptible/supercronic/releases/download/v0.1.12/supercronic-linux-amd64" \
         -O /usr/bin/supercronic \
    && chmod +x /usr/bin/supercronic \
    && mkdir /etc/supercronic \
    && echo '*/1 * * * * php /app/artisan schedule:run' > /etc/supercronic/laravel \
    # generate self-signed SSL key and certificate files
    && openssl req -x509 -nodes -days 1095 -newkey rsa:2048 \
        -subj "/C=CA/ST=QC/O=Company, Inc./CN=mydomain.com" \
        -addext "subjectAltName=DNS:mydomain.com" \
        -keyout /etc/ssl/private/selfsigned.key \
        -out /etc/ssl/certs/selfsigned.crt \
    && chmod 644 /etc/ssl/private/selfsigned.key \
    # create unprivileged user
    && adduser \
        --disabled-password \
        --shell "/sbin/nologin" \
        --home "/nonexistent" \
        --no-create-home \
        --uid "10001" \
        --gecos "" \
        "appuser" \
    # create directory for application sources
    && mkdir /app \
    && chown -R appuser:appuser /app \
    # make clean up
    && docker-php-source delete \
    && apk del .build-deps \
    && rm -R /tmp/pear

# add php-fpm customizations
COPY .build/php-fpm/laravel.ini /usr/local/etc/php/conf.d/

# copy composer (json|lock) files for dependencies layer caching
COPY --chown=appuser:appuser ./composer.* /app/

# load custom nginx.conf
COPY .build/nginx/nginx.conf /etc/nginx/

# add php-fpm upstream for nginx
RUN echo "upstream php-upstream { server ${PHP_CONTAINER}:${PHP_PORT}; }" > /etc/nginx/http.d/upstream.conf

COPY .build/nginx/site.conf /etc/nginx/http.d/default.conf

# use directory with application sources by default
WORKDIR /app

# install composer, image page: <https://hub.docker.com/_/composer>
COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME="/tmp/composer"

# install composer dependencies (autoloader MUST be generated later!)
RUN composer install -n --no-dev --no-cache --no-ansi --no-autoloader --no-scripts --prefer-dist

# copy application sources into image (completely)
COPY --chown=appuser:appuser . /app/

RUN set -x \
    # generate composer autoloader and trigger scripts
    && composer dump-autoload -n --optimize \
    # "fix" composer issue "Cannot create cache directory /tmp/composer/cache/..." for docker-compose usage
    && chmod -R 777 ${COMPOSER_HOME}/cache \
    # create the symbolic links configured for the application
    && php ./artisan storage:link

#    # copy front-end artifacts into image
#    COPY --from=frontend --chown=appuser:appuser /app/public /app/public

# use an unprivileged user by default
USER appuser:appuser

# unset default image entrypoint
ENTRYPOINT []
