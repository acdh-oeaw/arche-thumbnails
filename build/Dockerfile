FROM php:8.2-apache
# VARIANT must be "production" or "development"
ARG VARIANT
RUN cp "$PHP_INI_DIR/php.ini-$VARIANT" "$PHP_INI_DIR/php.ini"
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions
RUN apt update &&\
    apt install -y libmagickcore-6.q16-6-extra inkscape &&\
    install-php-extensions imagick yaml xml xmlreader pdo_pgsql @composer &&\
    a2enmod rewrite
COPY docroot /var/www/html
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN cd /var/www/html &&\
    composer update -o --no-dev &&\
    chown -R www-data:www-data /var/www/html
EXPOSE 80
ENTRYPOINT entrypoint.sh
