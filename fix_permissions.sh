#!/bin/bash
# fix_permissions.sh — Imposta permessi corretti per il server di produzione
# Eseguire come root su dev.local dopo ogni aggiornamento del progetto
# Uso: sudo bash fix_permissions.sh

set -euo pipefail

WEBROOT="/var/www/html/fatturazione"
WEB_USER="www-data"
WEB_GROUP="www-data"
OWNER="root"

# Cartelle che devono essere scrivibili da Apache/PHP per l'upload dei PDF
UPLOAD_DIRS=("fatture_elettroniche" "pdf")

# Cartella per i dump del database
DUMP_DIR="dump"

# ──────────────────────────────────────────────
# Controllo privilegi root
# ──────────────────────────────────────────────
if [[ "${EUID}" -ne 0 ]]; then
    echo "[ERRORE] Questo script deve essere eseguito come root."
    echo "         Usa: sudo bash fix_permissions.sh"
    exit 1
fi

# ──────────────────────────────────────────────
# Controllo che la webroot esista
# ──────────────────────────────────────────────
if [[ ! -d "${WEBROOT}" ]]; then
    echo "[ERRORE] La directory ${WEBROOT} non esiste sul server."
    exit 1
fi

echo "==> Fatturazione — fix permessi produzione"
echo "    Webroot : ${WEBROOT}"
echo "    Owner   : ${OWNER}:${WEB_GROUP}"
echo ""

# ──────────────────────────────────────────────
# 1. Ownership di default: root:www-data su tutto
# ──────────────────────────────────────────────
echo "[1/6] Imposto ownership root:${WEB_GROUP} su tutti i file..."
chown -R "${OWNER}:${WEB_GROUP}" "${WEBROOT}"

# ──────────────────────────────────────────────
# 2. Directory: 750 (owner rwx, group rx, altri niente)
# ──────────────────────────────────────────────
echo "[2/6] Imposto permessi 750 su tutte le directory..."
find "${WEBROOT}" -type d -exec chmod 750 {} \;

# ──────────────────────────────────────────────
# 3. File: 640 (owner rw, group r, altri niente)
# ──────────────────────────────────────────────
echo "[3/6] Imposto permessi 640 su tutti i file..."
find "${WEBROOT}" -type f -exec chmod 640 {} \;

# ──────────────────────────────────────────────
# 4. Cartelle di upload: 770 + proprietario www-data
#    PHP (www-data) deve poter scrivere i PDF uploadati
# ──────────────────────────────────────────────
echo "[4/6] Imposto permessi 770 sulle cartelle di upload..."
for dir in "${UPLOAD_DIRS[@]}"; do
    target="${WEBROOT}/${dir}"
    if [[ ! -d "${target}" ]]; then
        echo "      Creo directory mancante: ${target}"
        mkdir -p "${target}"
    fi
    chown -R "${WEB_USER}:${WEB_GROUP}" "${target}"
    chmod 770 "${target}"
    # Anche i file già presenti dentro la cartella devono essere scrivibili da www-data
    find "${target}" -type f -exec chmod 660 {} \;
    echo "      OK: ${target} → ${WEB_USER}:${WEB_GROUP} 770"
done

# ──────────────────────────────────────────────
# 5. Cartella dump: 750, solo owner/group leggono
# ──────────────────────────────────────────────
echo "[5/6] Imposto permessi 750 su ${DUMP_DIR}/..."
if [[ -d "${WEBROOT}/${DUMP_DIR}" ]]; then
    chmod 750 "${WEBROOT}/${DUMP_DIR}"
    find "${WEBROOT}/${DUMP_DIR}" -type f -exec chmod 640 {} \;
fi

# ──────────────────────────────────────────────
# 6. File sensibili: .env a 600 (solo owner)
# ──────────────────────────────────────────────
echo "[6/6] Imposto permessi 600 sui file sensibili..."
if [[ -f "${WEBROOT}/.env" ]]; then
    chmod 600 "${WEBROOT}/.env"
    echo "      OK: .env → 600"
fi
if [[ -f "${WEBROOT}/config.php" ]]; then
    chmod 600 "${WEBROOT}/config.php"
    echo "      OK: config.php → 600"
fi

# ──────────────────────────────────────────────
# Ripristino bit di esecuzione sullo script stesso
# ──────────────────────────────────────────────
chmod 750 "${WEBROOT}/fix_permissions.sh" 2>/dev/null || true

echo ""
echo "==> Permessi applicati correttamente."
echo ""
echo "    Riepilogo:"
echo "      Directory generiche : 750  (root:${WEB_GROUP})"
echo "      File generici       : 640  (root:${WEB_GROUP})"
printf "      Upload dirs         : 770  (%s:%s)" "${WEB_USER}" "${WEB_GROUP}"
for dir in "${UPLOAD_DIRS[@]}"; do echo -n "  [${dir}/]"; done
echo ""
echo "      File sensibili (.env, config.php) : 600"
echo ""
