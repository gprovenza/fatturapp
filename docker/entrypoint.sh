#!/bin/sh
# ============================================================
# fatturapp — Docker entrypoint
# Eseguito all'avvio del container web.
# ============================================================
set -e

APP_DIR="/var/www/html/fatturazione"

echo "[entrypoint] fatturapp avvio..."

# ── Assicura che le directory upload esistano e siano scrivibili ──
for dir in pdf fatture_elettroniche; do
    if [ ! -d "$APP_DIR/$dir" ]; then
        mkdir -p "$APP_DIR/$dir"
    fi
    chown -R www-data:www-data "$APP_DIR/$dir"
    chmod 770 "$APP_DIR/$dir"
done

echo "[entrypoint] Directory upload OK."

# ── Attendi che MariaDB sia pronto ────────────────────────────
if [ -n "$DB_SERVER" ] && [ -n "$DB_USERNAME" ] && [ -n "$DB_PASSWORD" ] && [ -n "$DB_NAME" ]; then
    echo "[entrypoint] Attendo MariaDB ($DB_SERVER)..."
    RETRIES=30
    until php -r "
        \$c = @mysqli_connect('$DB_SERVER','$DB_USERNAME','$DB_PASSWORD','$DB_NAME');
        exit(\$c ? 0 : 1);
    " 2>/dev/null || [ "$RETRIES" -eq 0 ]; do
        echo "[entrypoint]   DB non pronto, attendo 2s... ($RETRIES tentativi rimasti)"
        RETRIES=$((RETRIES - 1))
        sleep 2
    done

    if [ "$RETRIES" -eq 0 ]; then
        echo "[entrypoint] ERRORE: impossibile connettersi al DB dopo 60s."
        exit 1
    fi
    echo "[entrypoint] DB connesso."

    # ── Auto-migration al primo avvio ─────────────────────────
    # Verifica se saas_plans esiste; se no, esegue la migration
    TABLES_EXIST=$(php -r "
        \$c = mysqli_connect('$DB_SERVER','$DB_USERNAME','$DB_PASSWORD','$DB_NAME');
        \$r = mysqli_query(\$c, 'SHOW TABLES LIKE \"saas_plans\"');
        echo mysqli_num_rows(\$r);
    " 2>/dev/null || echo "0")

    if [ "$TABLES_EXIST" = "0" ]; then
        MIGRATION="$APP_DIR/migrations/001_saas_foundation.sql"
        if [ -f "$MIGRATION" ]; then
            echo "[entrypoint] Eseguo migration SaaS foundation..."
            php -r "
                \$c = mysqli_connect('$DB_SERVER','$DB_USERNAME','$DB_PASSWORD','$DB_NAME');
                \$sql = file_get_contents('$MIGRATION');
                mysqli_multi_query(\$c, \$sql);
                do { mysqli_store_result(\$c); } while (mysqli_more_results(\$c) && mysqli_next_result(\$c));
                echo 'Migration completata.' . PHP_EOL;
            "
        else
            echo "[entrypoint] ATTENZIONE: migration non trovata, saltato."
        fi
    else
        echo "[entrypoint] Schema SaaS già presente, migration saltata."
    fi
fi

echo "[entrypoint] Avvio Apache..."
exec apache2-foreground "$@"
