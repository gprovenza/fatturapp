<?php
/**
 * Checklist configurazione piattaforma fatturapp.
 * Accessibile solo all'admin SaaS.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../db.php';

$conn = getDBConnection();

// ── Check funzioni helper ─────────────────────────────────────
function check(bool $ok, string $label, string $detail = ''): array {
    return ['ok' => $ok, 'label' => $label, 'detail' => $detail];
}

$checks = [];

// 1. DB: tabelle SaaS presenti
$tables_required = ['saas_plans', 'saas_tenants', 'saas_subscriptions', 'saas_payments', 'saas_tenant_settings', 'saas_gdpr_exports'];
$missing_tables = [];
foreach ($tables_required as $t) {
    $res = mysqli_query($conn, "SHOW TABLES LIKE '$t'");
    if (!mysqli_num_rows($res)) $missing_tables[] = $t;
}
$checks[] = check(empty($missing_tables), 'Schema DB SaaS completo',
    empty($missing_tables) ? 'Tutte le tabelle SaaS presenti' : 'Mancanti: ' . implode(', ', $missing_tables));

// 2. DB: colonne tenant_id nelle tabelle business
$biz_columns = [
    'tb_utenti'              => 'tenant_id',
    'tb_anagrafiche'         => 'tenant_id',
    'tb_clienti'             => 'tenant_id',
    'tb_progetti'            => 'tenant_id',
    'tb_fatture'             => 'tenant_id',
    'tb_fatture_dettaglio'   => 'tenant_id',
    'tb_fatture_elettroniche'=> 'tenant_id',
    'tb_ore_lavoro'          => 'tenant_id',
];
$missing_cols = [];
foreach ($biz_columns as $tbl => $col) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$tbl` LIKE '$col'");
    if (!mysqli_num_rows($res)) $missing_cols[] = "$tbl.$col";
}
$checks[] = check(empty($missing_cols), 'Colonne tenant_id nelle tabelle business',
    empty($missing_cols) ? 'Tutte le colonne presenti' : 'Mancanti: ' . implode(', ', $missing_cols));

// 3. DB: piani configurati
$res = mysqli_query($conn, "SELECT COUNT(*) FROM saas_plans WHERE is_active=1");
$plan_count = (int)mysqli_fetch_row($res)[0];
$checks[] = check($plan_count >= 2, "Piani abbonamento ($plan_count attivi)",
    $plan_count >= 2 ? 'Free + Pro configurati' : 'Eseguire migration 001_saas_foundation.sql');

// 4. Tenant 1 (owner) configurato
$res = mysqli_query($conn, "SELECT COUNT(*) FROM saas_tenants WHERE id=1");
$t1 = (int)mysqli_fetch_row($res)[0];
$checks[] = check($t1 === 1, 'Tenant admin (id=1) presente',
    $t1 === 1 ? 'Tenant owner configurato' : 'Mancante — eseguire migration');

// 5. PayPal: CLIENT_ID configurato
$checks[] = check(!empty(PAYPAL_CLIENT_ID), 'PAYPAL_CLIENT_ID configurato',
    !empty(PAYPAL_CLIENT_ID) ? 'Modalità: ' . PAYPAL_MODE : 'Mancante nel .env');

// 6. PayPal: PLAN_ID configurato
$checks[] = check(!empty(PAYPAL_PLAN_ID_PRO), 'PAYPAL_PLAN_ID_PRO configurato',
    !empty(PAYPAL_PLAN_ID_PRO) ? PAYPAL_PLAN_ID_PRO : 'Eseguire: php setup/create-paypal-plan.php');

// 7. PayPal: test connessione (solo se credenziali presenti)
if (!empty(PAYPAL_CLIENT_ID) && !empty(PAYPAL_CLIENT_SECRET)) {
    try {
        require_once __DIR__ . '/../../saas/paypal/paypal-api.php';
        $tok = getPayPalToken();
        $checks[] = check(!empty($tok), 'Connessione PayPal API', 'Token ottenuto con successo');
    } catch (\Throwable $e) {
        $checks[] = check(false, 'Connessione PayPal API', 'Errore: ' . $e->getMessage());
    }
} else {
    $checks[] = check(false, 'Connessione PayPal API', 'Credenziali mancanti');
}

// 8. SMTP: configurato
$checks[] = check(!empty(getenv('SMTP_HOST')), 'SMTP configurato',
    getenv('SMTP_HOST') ? getenv('SMTP_HOST') . ':' . getenv('SMTP_PORT') : 'Userà mail() di sistema (inaffidabile)');

// 9. SITE_URL configurato
$checks[] = check(SITE_URL !== 'https://web.prov-home.dedyn.io/fatturazione/', 'SITE_URL configurato per produzione',
    SITE_URL);

// 10. Directory upload scrivibili
$upload_dirs = ['fatture_elettroniche/', 'pdf/'];
foreach ($upload_dirs as $dir) {
    $full = __DIR__ . '/../../' . $dir;
    $ok   = is_dir($full) && is_writable($full);
    $checks[] = check($ok, "Directory $dir scrivibile",
        $ok ? 'OK' : 'mkdir o chmod 770 richiesto');
}

// 11. Cron: verifica esistenza script
$cron_ok = file_exists(__DIR__ . '/../../cron/subscription-maintenance.php');
$checks[] = check($cron_ok, 'Script cron presente',
    $cron_ok ? 'Configura: 0 8 * * * php /var/www/html/fatturazione/cron/subscription-maintenance.php' : 'File mancante');

// 12. HTTPS
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$checks[] = check($is_https, 'HTTPS attivo', $is_https ? 'Connessione sicura' : 'Raccomandato per produzione');

mysqli_close($conn);

// Conteggio
$ok_count  = count(array_filter($checks, fn($c) => $c['ok']));
$tot_count = count($checks);
$all_ok    = $ok_count === $tot_count;

$page_title   = 'Admin — Setup & Checklist';
$current_page = 'saas/admin/setup.php';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="page-content">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="mb-0">
            <i class="bi bi-gear text-danger me-2"></i>Setup & Checklist
        </h2>
        <div class="d-flex gap-2">
            <a href="?refresh=1" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-clockwise me-1"></i> Aggiorna
            </a>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-speedometer2 me-1"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Stato generale -->
    <div class="alert alert-<?= $all_ok ? 'success' : ($ok_count >= $tot_count * 0.7 ? 'warning' : 'danger') ?> d-flex align-items-center gap-3 mb-4">
        <i class="bi bi-<?= $all_ok ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> fs-3"></i>
        <div>
            <strong><?= $all_ok ? '🎉 Configurazione completa!' : "Configurazione: $ok_count/$tot_count checks superati" ?></strong>
            <?php if (!$all_ok): ?>
            <div class="small mt-1">Risolvi i check in rosso prima di andare in produzione.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Checklist -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-0">
            <table class="table mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="40"></th>
                        <th>Check</th>
                        <th>Dettaglio</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checks as $c): ?>
                    <tr>
                        <td class="text-center py-3">
                            <?php if ($c['ok']): ?>
                            <i class="bi bi-check-circle-fill text-success fs-5"></i>
                            <?php else: ?>
                            <i class="bi bi-x-circle-fill text-danger fs-5"></i>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 fw-semibold <?= $c['ok'] ? '' : 'text-danger' ?>">
                            <?= e($c['label']) ?>
                        </td>
                        <td class="py-3 text-muted small"><?= e($c['detail']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Guide rapide -->
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom fw-bold">
                    <i class="bi bi-paypal me-2 text-primary"></i>Setup PayPal
                </div>
                <div class="card-body">
                    <ol class="mb-0 small">
                        <li class="mb-2">Accedi a <a href="https://developer.paypal.com" target="_blank">developer.paypal.com</a></li>
                        <li class="mb-2">Crea un'app Sandbox → copia <code>Client ID</code> e <code>Secret</code> nel <code>.env</code></li>
                        <li class="mb-2">Esegui da terminale:
                            <code class="d-block bg-light p-2 rounded mt-1">php setup/create-paypal-plan.php</code>
                        </li>
                        <li class="mb-2">Copia il <code>PAYPAL_PLAN_ID_PRO</code> nel <code>.env</code></li>
                        <li class="mb-2">Configura il webhook su PayPal puntando a:<br>
                            <code class="d-block bg-light p-2 rounded mt-1 text-break"><?= e(APP_URL) ?>saas/paypal/webhook.php</code>
                        </li>
                        <li>Copia il <code>PAYPAL_WEBHOOK_ID</code> nel <code>.env</code></li>
                    </ol>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom fw-bold">
                    <i class="bi bi-clock me-2 text-warning"></i>Setup Cron
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-2">Aggiungi questa riga al crontab del server:</p>
                    <code class="d-block bg-dark text-white p-3 rounded small mb-3">
                        0 8 * * * /usr/bin/php /var/www/html/fatturazione/cron/subscription-maintenance.php &gt;&gt; /var/log/fatturapp-cron.log 2&gt;&amp;1
                    </code>
                    <p class="small text-muted mb-0">
                        Esegue ogni giorno alle 08:00: scade i trial, invia promemoria,
                        pulisce i token scaduti (GDPR).
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom fw-bold">
                    <i class="bi bi-envelope me-2 text-info"></i>Setup Email
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-2">Aggiungi nel <code>.env</code>:</p>
                    <pre class="bg-light p-2 rounded small mb-0">SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=tua@email.it
SMTP_PASSWORD=app-password-gmail
SMTP_FROM=noreply@fatturapp.it
SMTP_FROM_NAME=fatturapp</pre>
                    <p class="small text-muted mt-2 mb-0">
                        Per Gmail usa una <a href="https://myaccount.google.com/apppasswords" target="_blank">App Password</a>
                        (non la password dell'account).
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom fw-bold">
                    <i class="bi bi-shield-check me-2 text-success"></i>Produzione
                </div>
                <div class="card-body small">
                    <ul class="mb-0 ps-3">
                        <li class="mb-1">Cambia <code>PAYPAL_MODE=live</code> e usa le credenziali Live</li>
                        <li class="mb-1">Abilita HSTS in <code>.htaccess</code> (decommentare la riga)</li>
                        <li class="mb-1">Forza redirect HTTP→HTTPS in <code>.htaccess</code></li>
                        <li class="mb-1">Imposta <code>SITE_URL</code> con l'URL pubblico definitivo</li>
                        <li class="mb-1">Verifica che <code>fatture_elettroniche/</code> e <code>pdf/</code> abbiano permessi 770</li>
                        <li>Testa il cron con: <code>php cron/subscription-maintenance.php</code></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
