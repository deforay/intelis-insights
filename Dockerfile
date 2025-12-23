FROM php:8.2-apache

# Install extensions and tools needed for the app
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libonig-dev \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Install Composer (copy from official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app

# Install PHP deps
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Copy the application
COPY . .

# Ensure default configs exist inside the image (env can override)
RUN if [ ! -f config/app.php ] && [ -f config/app.dist.php ]; then cp config/app.dist.php config/app.php; fi \
    && if [ ! -f config/db.php ] && [ -f config/db.dist.php ]; then cp config/db.dist.php config/db.php; fi

# Refresh autoloader after app code is copied
RUN composer dump-autoload --no-dev --optimize

# Apache vhost: point to public/ and allow rewrites
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/app/public#g' /etc/apache2/sites-available/000-default.conf \
    && printf "<Directory /var/www/app/public>\\n\\tAllowOverride All\\n\\tRequire all granted\\n\\tFallbackResource /index.php\\n</Directory>\\n" > /etc/apache2/conf-available/app.conf \
    && a2enconf app

# Make runtime dirs writable
RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var \
    && chmod -R 775 var

ENV APACHE_DOCUMENT_ROOT=/var/www/app/public

EXPOSE 80

CMD ["apache2-foreground"]
