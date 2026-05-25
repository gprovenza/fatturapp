# ============================================================
# fatturapp — Dockerfile PHP 8.4 + Apache
# Immagine self-contained: il codice è incluso (no bind-mount).
# Push automatico su GHCR tramite GitHub Actions.
# ============================================================
FROM php:8.4-apache

# Installa estensioni PHP e dipendenze di sistema
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

# Copia configurazione Apache + entrypoint
COPY docker/apache-fatturapp.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/fatturapp-entrypoint
RUN chmod +x /usr/local/bin/fatturapp-entrypoint

# Configura PHP per produzione
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    sed -i 's/upload_max_filesize = 2M/upload_max_filesize = 20M/'  "$PHP_INI_DIR/php.ini" && \
    sed -i 's/post_max_size = 8M/post_max_size = 25M/'              "$PHP_INI_DIR/php.ini" && \
    sed -i 's/memory_limit = 128M/memory_limit = 256M/'             "$PHP_INI_DIR/php.ini" && \
    sed -i 's/max_execution_time = 30/max_execution_time = 60/'     "$PHP_INI_DIR/php.ini" && \
    sed -i 's/expose_php = On/expose_php = Off/'                    "$PHP_INI_DIR/php.ini"

# Directory di lavoro
WORKDIR /var/www/html/fatturazione

# ── Copia codice applicazione nell'immagine ──────────────────
# Esclude file non necessari a runtime (vedi .dockerignore)
COPY --chown=root:www-data . .

# Crea directory upload vuote (i file reali vengono montati come volumi)
RUN mkdir -p pdf fatture_elettroniche && \
    chown -R www-data:www-data pdf fatture_elettroniche && \
    chmod 770 pdf fatture_elettroniche

# Permessi corretti sul codice: root:www-data, file 640, dir 750
RUN find . -type d -exec chmod 750 {} \; && \
    find . -type f -exec chmod 640 {} \; && \
    chmod 750 fix_permissions.sh cron/subscription-maintenance.php setup/create-paypal-plan.php 2>/dev/null || true

EXPOSE 80

# Healthcheck: Apache risponde su /fatturazione/login.php
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -fsS http://localhost/fatturazione/login.php | grep -q "fattur" || exit 1

ENTRYPOINT ["fatturapp-entrypoint"]
