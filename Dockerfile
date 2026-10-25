# Imagem do sistema Futebol: PHP 8.2 + Apache (com .htaccess) + extensão PDO MySQL.
FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && sed -ri '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

ENV TZ=America/Sao_Paulo
WORKDIR /var/www/html
