FROM php:7.2-cli

# Install dependencies
RUN apt-get update && apt-get install -y \
    git unzip zip curl \
    && docker-php-ext-install pdo pdo_mysql

# Install Xdebug for code coverage
RUN pecl install xdebug-2.9.8 \
    && docker-php-ext-enable xdebug

# Configure Xdebug for coverage (optional - creates xdebug.ini)
RUN echo "xdebug.mode=coverage" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

CMD ["php", "-a"]
