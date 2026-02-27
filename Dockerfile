# ==============================================================
# Intelis Insights — PHP Application
# ==============================================================
# Targets:
#   development  — PHP built-in server, source bind-mounted
#   production   — nginx + php-fpm, vendored deps baked in
# ==============================================================

# ---- Base: PHP with required extensions ----
FROM php:8.4-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        unzip \
        curl \
        git \
        default-mysql-client \
    && docker-php-ext-install pdo_mysql mbstring zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# ---- Dependencies: install composer packages ----
FROM base AS deps
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# ---- Development target ----
FROM base AS development
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress
# Source code is volume-mounted in docker-compose
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]

# ---- Production target ----
FROM base AS production

RUN apt-get update && apt-get install -y --no-install-recommends nginx \
    && rm -rf /var/lib/apt/lists/*

# Copy vendored deps from deps stage
COPY --from=deps /var/www/vendor ./vendor

# Copy application code
COPY . .

# Nginx config
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Create runtime directories
RUN mkdir -p var/cache var/logs corpus \
    && chown -R www-data:www-data var corpus

EXPOSE 8080

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
