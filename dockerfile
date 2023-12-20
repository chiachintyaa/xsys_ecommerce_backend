FROM php:8.2-alpine
RUN apt-get update && apt-get install -y libmemcached-dev libssl-dev zlib1g-dev \
	&& pecl install memcached-3.2.0 \
	&& docker-php-ext-enable memcached

WORKDIR /var/www/html

RUN curl -sS https://getcomposer.org/installer | php -- --version=2.6.3 --install-dir=/usr/local/bin --filename=composer

COPY . .

RUN composer install

EXPOSE 8001

CMD ["php","artisan","serve","--host=0.0.0.0"]

