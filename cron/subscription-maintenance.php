#!/usr/bin/env php
<?php
/**
 * fatturapp — Manutenzione abbonamenti (cron giornaliero)
 * ─────────────────────────────────────────────────────────────
 * Eseguire ogni giorno via crontab:
 *
 *   # Ogni giorno alle 08:00
 *   0 8 * * * /usr/bin/php /var/www/html/fatturazione/cron/subscription-maintenance.php >> /var/log/fatturapp-cron.log 2>&1
 *
 * Operazioni:
 *   1. Trial scaduti → status='expired', downgrade a Free, email notifica
 *   2. Trial in scadenza (3 gg, 1 gg) → email promemoria upgrade
 *   3. Pro cancellati con periodo scaduto → status='expired'
 *   4. Pulizia token verifica/reset scaduti (privacy)
 * ─────────────────────────────────────────────────────────────
 */

define('CLI_MODE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/mailer.php';

// ── Helper di log ──────────────────────────────────────────────
function clog(string $msg, string $level = 'INFO'): void {
    echo '[' . date('Y-m-d H:i:s') . "] [$level] $msg\n";
}

clog("=== fatturapp subscription-maintenance START ===");
$conn = getDBConnection();

$upgradeUrl = APP_URL . 'saas/plans.php';
$now        = date('Y-m-d H:i:s');
$today      = date('Y-m-d');

$stats = [
    'trials_expired'   => 0,
    'reminders_sent'   => 0,
    'cancelled_closed' => 0,
    'tokens_cleaned'   => 0,
    'errors'           => 0,
];

// ══════════════════════════════════════════════════════════════
// 1. TRIAL SCADUTI → Downgrade a Free
// ══════════════════════════════════════════════════════════════
clog("Cerco trial scaduti...");

$stm = mysqli_prepare($conn,
    "SELECT s.tenant_id, s.id AS sub_id, u.email, u.username
     FROM saas_subscriptions s
     JOIN saas_tenants t      ON t.id        = s.tenant_id
     JOIN tb_utenti u         ON u.id_utente = t.owner_user_id
     WHERE s.status = 'trial'
       AND s.trial_ends_at IS NOT NULL
       AND s.trial_ends_at < NOW()");
mysqli_stmt_execute($stm);
$expired_trials = mysqli_fetch_all(mysqli_stmt_get_result($stm), MYSQLI_ASSOC);

if (!empty($expired_trials)) {
    // Ottieni ID piano Free
    $stm_free = mysqli_prepare($conn, "SELECT id FROM saas_plans WHERE LOWER(name)='free' LIMIT 1");
    mysqli_stmt_execute($stm_free);
    $free_id = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stm_free))['id'] ?? 1);

    foreach ($expired_trials as $row) {
        try {
            // Downgrade a Free
            $stm_upd = mysqli_prepare($conn,
                "UPDATE saas_subscriptions
                 SET status='expired', plan_id=?, trial_ends_at=NULL
                 WHERE id=?");
            mysqli_stmt_bind_param($stm_upd, 'ii', $free_id, $row['sub_id']);
            mysqli_stmt_execute($stm_upd);

            // Invia email notifica scadenza
            $sent = sendMail(
                $row['email'],
                'Il tuo trial fatturapp è scaduto',
                mailTrialExpiredHtml($upgradeUrl)
            );

            $stats['trials_expired']++;
            clog("Trial scaduto: tenant={$row['tenant_id']} email={$row['email']} mail=" . ($sent ? 'OK' : 'FAIL'));
        } catch (\Throwable $e) {
            clog("Errore trial expired tenant={$row['tenant_id']}: " . $e->getMessage(), 'ERROR');
            $stats['errors']++;
        }
    }
} else {
    clog("Nessun trial scaduto.");
}

// ══════════════════════════════════════════════════════════════
// 2. PROMEMORIA TRIAL IN SCADENZA (3 giorni e 1 giorno)
// ══════════════════════════════════════════════════════════════
clog("Cerco trial in scadenza per promemoria...");

// I promemoria vengono inviati quando trial_ends_at è tra oggi e X giorni
// Per evitare doppi invii usiamo la data esatta (non intervallo ampio)
$reminder_days = [3, 1];

foreach ($reminder_days as $days) {
    $target_date = date('Y-m-d', strtotime("+$days days"));

    $stm = mysqli_prepare($conn,
        "SELECT s.tenant_id, s.id AS sub_id, u.email, u.username
         FROM saas_subscriptions s
         JOIN saas_tenants t ON t.id        = s.tenant_id
         JOIN tb_utenti u    ON u.id_utente = t.owner_user_id
         WHERE s.status = 'trial'
           AND DATE(s.trial_ends_at) = ?");
    mysqli_stmt_bind_param($stm, 's', $target_date);
    mysqli_stmt_execute($stm);
    $expiring = mysqli_fetch_all(mysqli_stmt_get_result($stm), MYSQLI_ASSOC);

    foreach ($expiring as $row) {
        try {
            $sent = sendMail(
                $row['email'],
                "Il tuo trial fatturapp scade tra $days " . ($days === 1 ? 'giorno' : 'giorni'),
                mailTrialReminderHtml($upgradeUrl, $days)
            );
            $stats['reminders_sent']++;
            clog("Reminder {$days}gg: tenant={$row['tenant_id']} email={$row['email']} mail=" . ($sent ? 'OK' : 'FAIL'));
        } catch (\Throwable $e) {
            clog("Errore reminder {$days}gg tenant={$row['tenant_id']}: " . $e->getMessage(), 'ERROR');
            $stats['errors']++;
        }
    }
}

// ══════════════════════════════════════════════════════════════
// 3. PRO CANCELLATI CON PERIODO SCADUTO → expired
// ══════════════════════════════════════════════════════════════
clog("Cerco abbonamenti cancellati con periodo scaduto...");

$stm = mysqli_prepare($conn,
    "UPDATE saas_subscriptions
     SET status='expired'
     WHERE status='cancelled'
       AND current_period_end IS NOT NULL
       AND current_period_end < CURDATE()");
mysqli_stmt_execute($stm);
$stats['cancelled_closed'] = mysqli_affected_rows($conn);
if ($stats['cancelled_closed'] > 0) {
    clog("Chiusi {$stats['cancelled_closed']} abbonamenti cancellati scaduti.");
}

// ══════════════════════════════════════════════════════════════
// 4. PULIZIA TOKEN SCADUTI (GDPR — minimizzazione dati)
// ══════════════════════════════════════════════════════════════
clog("Pulizia token scaduti...");

$stm = mysqli_prepare($conn,
    "UPDATE tb_utenti
     SET verification_token=NULL, verification_token_exp=NULL
     WHERE verification_token IS NOT NULL
       AND verification_token_exp < NOW()");
mysqli_stmt_execute($stm);
$cleaned1 = mysqli_affected_rows($conn);

$stm = mysqli_prepare($conn,
    "UPDATE tb_utenti
     SET reset_token=NULL, reset_token_exp=NULL
     WHERE reset_token IS NOT NULL
       AND reset_token_exp < NOW()");
mysqli_stmt_execute($stm);
$cleaned2 = mysqli_affected_rows($conn);

$stats['tokens_cleaned'] = $cleaned1 + $cleaned2;
if ($stats['tokens_cleaned'] > 0) {
    clog("Puliti {$stats['tokens_cleaned']} token scaduti.");
}

// ══════════════════════════════════════════════════════════════
// RIEPILOGO
// ══════════════════════════════════════════════════════════════
mysqli_close($conn);
clog("=== RIEPILOGO ===");
clog("  Trial scaduti downgraded : {$stats['trials_expired']}");
clog("  Reminder inviati         : {$stats['reminders_sent']}");
clog("  Cancellati chiusi        : {$stats['cancelled_closed']}");
clog("  Token puliti             : {$stats['tokens_cleaned']}");
clog("  Errori                   : {$stats['errors']}");
clog("=== fatturapp subscription-maintenance END ===\n");

exit($stats['errors'] > 0 ? 1 : 0);
