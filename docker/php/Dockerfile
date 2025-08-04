FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    libsodium-dev \
    unzip

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip sodium intl opcache

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY ./php.ini /usr/local/etc/php/conf.d/laravel.ini
COPY ./php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY . /var/www

RUN usermod -u 1001 www-data && groupmod -g 1001 www-data
RUN chown -R www-data:www-data /var/www/public

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
