<?php
/**
 * Configurazione applicazione.
 *
 * Le credenziali vengono lette da un file .env nella root del progetto,
 * se presente. In caso contrario si usano i valori di fallback qui sotto.
 *
 * File .env di esempio (NON aggiungere al repository):
 *   DB_SERVER=localhost
 *   DB_USERNAME=utente
 *   DB_PASSWORD=password
 *   DB_NAME=fatturazione
 *   SITE_URL=https://tuosito.it/fatturazione/
 */

// Carica .env se esiste
$_env_file = __DIR__ . '/.env';
if (is_readable($_env_file)) {
    foreach (file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if ($_line === '' || $_line[0] === '#') continue;
        if (str_contains($_line, '=')) {
            [$_key, $_val] = array_map('trim', explode('=', $_line, 2));
            if (!empty($_key)) putenv("$_key=$_val");
        }
    }
}
unset($_env_file, $_line, $_key, $_val);

// Configurazione database
define('DB_SERVER',   getenv('DB_SERVER')   ?: 'localhost');
define('DB_USERNAME', getenv('DB_USERNAME') ?: 'gprovenzano');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'smsdante');
define('DB_NAME',     getenv('DB_NAME')     ?: 'fatturazione');

// Configurazione applicazione
define('SITE_URL',    getenv('SITE_URL')    ?: 'https://web.prov-home.dedyn.io/fatturazione/');
define('PDF_DIR',     __DIR__ . '/pdf/');
define('UPLOAD_DIR',  __DIR__ . '/fatture_elettroniche/');

// Costanti business
define('TAX_PERCENTAGE',  35.0);   // % tasse forfettarie
define('MARCA_BOLLO',      2.0);   // € marca da bollo
define('MAX_UPLOAD_MB',   20);     // MB max per upload fatture
define('SESSION_TIMEOUT', 1800);   // secondi di inattività prima del logout

// SaaS — PayPal REST API v2
define('PAYPAL_CLIENT_ID',     getenv('PAYPAL_CLIENT_ID')     ?: '');
define('PAYPAL_CLIENT_SECRET', getenv('PAYPAL_CLIENT_SECRET') ?: '');
define('PAYPAL_PLAN_ID_PRO',   getenv('PAYPAL_PLAN_ID_PRO')   ?: '');   // ID piano PayPal mensile €7
define('PAYPAL_MODE',          getenv('PAYPAL_MODE')          ?: 'sandbox'); // 'sandbox' | 'live'
define('PAYPAL_API_BASE',      PAYPAL_MODE === 'live'
    ? 'https://api-m.paypal.com'
    : 'https://api-m.sandbox.paypal.com');

// SaaS — Email (usato da includes/mailer.php)
define('SMTP_FROM',      getenv('SMTP_FROM')      ?: 'noreply@fatturapp.it');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'fatturapp');

// SaaS — URL base app (usato nei link email)
define('APP_URL', rtrim(SITE_URL, '/') . '/');

// Errori: mostra in development, nascondi in production
if (defined('APP_ENV') && APP_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}
