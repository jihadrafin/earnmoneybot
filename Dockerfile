FROM php:8.1-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install zip

# Enable Apache modules
RUN a2enmod rewrite headers

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set proper permissions for files
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    # Make error.log and users.json writable
    && touch /var/www/html/error.log \
    && touch /var/www/html/users.json \
    && chmod 666 /var/www/html/error.log \
    && chmod 666 /var/www/html/users.json

# Configure Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Expose port
EXPOSE 8080

# Start Apache in foreground
CMD ["apache2-foreground"]