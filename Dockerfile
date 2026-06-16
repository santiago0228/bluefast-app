FROM php:8.2-apache

# Instalamos pdo_mysql directamente
RUN docker-php-ext-install pdo pdo_mysql

# Esto es lo único necesario para copiar tu código
COPY . /var/www/html/

# No necesitamos EXPOSE ni configuraciones extra de MPM