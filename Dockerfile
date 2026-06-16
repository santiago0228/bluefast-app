FROM php:8.2-apache

# Instalamos el driver de MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Configuramos Apache para el puerto 8080 de forma segura
RUN sed -i 's/80/8080/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Copiamos tu código
COPY . /var/www/html/

# Ajustamos permisos para que no haya problemas de lectura
RUN chown -R www-data:www-data /var/www/html