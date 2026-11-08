FROM php:8.2-apache

# Check what MPM configs exist
RUN find /etc/apache2 -name "*.load" -path "*mpm*" 2>/dev/null; echo "==="; find /etc/apache2 -name "*.conf" | xargs grep -l "mpm_" 2>/dev/null | head -5

# Remove MPM module files entirely
RUN find /etc/apache2/mods-enabled -name "mpm_*" -delete
RUN find /etc/apache2/mods-available -name "mpm_*" -delete

# Enable only prefork
RUN a2enmod mpm_prefork

# Enable Apache modules
RUN a2enmod rewrite headers

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Set document root
ENV APACHE_DOCUMENT_ROOT /var/www/html

# Copy app
COPY . /var/www/html/

# Permissions
RUN chown -R www-data:www-data /var/www/html

# Apache config
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

EXPOSE 80
