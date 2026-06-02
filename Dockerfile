FROM php:8.2-fpm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PATH="/root/.composer/vendor/bin:/root/.symfony/bin:${PATH}"

RUN apt-get update && apt-get install -y \
    git unzip curl libpq-dev libonig-dev zlib1g-dev \
    && docker-php-ext-install pdo pdo_pgsql mbstring opcache bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/php/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
