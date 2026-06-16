FROM php:8.2-apache

# Instalamos el driver de MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Configuramos Apache para que escuche en el puerto 8080
RUN sed -i 's/80/8080/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Copiamos tu código
COPY . /var/www/html/