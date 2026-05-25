# ============================================================
# fatturapp — Dockerfile PHP 8.4 + Apache
# ============================================================
FROM php:8.4-apache

# Installa estensioni PHP necessarie
RUN apt-get update && apt-get install -y \
        libzip-dev \
        zip \
        unzip \
        libpng-dev \
        libjpeg-dev \
    && docker-php-ext-install \
        mysqli \
        pdo \
        pdo_mysql \
        zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Abilita moduli Apache
RUN a2enmod rewrite headers expires deflate

# Copia configurazione Apache personalizzata
COPY docker/apache-fatturapp.conf /etc/apache2/sites-available/000-default.conf

# Configura PHP per produzione
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    sed -i 's/upload_max_filesize = 2M/upload_max_filesize = 20M/' "$PHP_INI_DIR/php.ini" && \
    sed -i 's/post_max_size = 8M/post_max_size = 25M/' "$PHP_INI_DIR/php.ini" && \
    sed -i 's/memory_limit = 128M/memory_limit = 256M/' "$PHP_INI_DIR/php.ini" && \
    sed -i 's/max_execution_time = 30/max_execution_time = 60/' "$PHP_INI_DIR/php.ini" && \
    sed -i 's/expose_php = On/expose_php = Off/' "$PHP_INI_DIR/php.ini"

# Directory applicazione
WORKDIR /var/www/html/fatturazione

# Crea directory upload con permessi corretti
RUN mkdir -p pdf fatture_elettroniche && \
    chown -R www-data:www-data pdf fatture_elettroniche && \
    chmod 770 pdf fatture_elettroniche

EXPOSE 80
