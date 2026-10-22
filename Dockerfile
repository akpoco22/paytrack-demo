FROM php:8.2-apache

# Install SQLite development headers and PDO SQLite driver
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers

# Copy application files
WORKDIR /var/www/html
COPY . /var/www/html/

# Ensure Apache can read and write to the directory and SQLite database
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html \
    && touch /var/www/html/paytrack.db \
    && chown www-data:www-data /var/www/html/paytrack.db \
    && chmod 664 /var/www/html/paytrack.db

# Configure Apache port (Render supplies PORT env var, defaults to 80)
EXPOSE 80

CMD ["apache2-foreground"]
