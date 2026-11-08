FROM php:8.2-apache

# Custom entrypoint to disable extra MPMs before starting Apache
RUN printf '#!/bin/bash\nfor m in mpm_event mpm_worker; do a2dismod $m 2>/dev/null; done\na2enmod mpm_prefork 2>/dev/null\n# Use Railway PORT\nPORT=${PORT:-80}\nsed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf\nsed -i "s/:80/:$PORT/" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground\n' > /usr/local/bin/start-apache.sh && chmod +x /usr/local/bin/start-apache.sh

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

CMD ["/usr/local/bin/start-apache.sh"]
EXPOSE 8080
