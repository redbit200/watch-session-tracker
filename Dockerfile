FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy dependency manifests first to leverage Docker layer cache
COPY composer.json composer.lock* ./

# Install PHP dependencies (no dev in prod; for this PoC we install everything)
RUN composer install --no-interaction --prefer-dist --no-scripts

# Copy application source
COPY . .

# Run post-install scripts (cache warmup, etc.)
RUN composer run-script post-install-cmd --no-interaction

# Create the var/ directory and ensure SQLite can write there
RUN mkdir -p var && chmod 777 var

EXPOSE 8080

# Use PHP's built-in web server — fine for a PoC, not for production
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public/"]
