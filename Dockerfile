FROM php:8.2-apache
RUN docker-php-ext-install mysqli && a2enmod rewrite
COPY web/ /var/www/html/smart_parking_integrated/
RUN mkdir -p /var/www/html && echo '<?php header("Location: /smart_parking_integrated/login.php");' > /var/www/html/index.php \
 && chown -R www-data:www-data /var/www/html/smart_parking_integrated/uploads /var/www/html/smart_parking_integrated/camera_images
