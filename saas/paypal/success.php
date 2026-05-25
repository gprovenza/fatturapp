<?php
/**
 * PayPal ritorna qui dopo l'approvazione dell'utente.
 * Verifica e attiva l'abbonamento nel database.
 */
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/paypal-api.php';

$tenant_id = getTenantId();

// Verifica che l'abbonamento in sessione corrisponda al tenant corrente
$subscription_id = $_SESSION['paypal_subscription_id'] ?? '';
$session_tenant  = (int)($_SESSION['paypal_tenant_id'] ?? 0);

if (empty($subscription_id) || $session_tenant !== $tenant_id) {
    set_flash('Sessione non valida. Riprova.', 'danger');
    header('Location: ' . APP_URL . 'saas/plans.php');
    exit;
}

// Pulisci variabili di sessione temporanee
unset($_SESSION['paypal_subscription_id'], $_SESSION['paypal_tenant_id']);

try {
    // Verifica lo stato dell'abbonamento su PayPal
    $sub    = getPayPalSubscription($subscription_id);
    $status = strtoupper($sub['status'] ?? '');

    // APPROVAL_PENDING: l'utente ha approvato ma PayPal non ha ancora elaborato
    // ACTIVE: già attivo
    if (!in_array($status, ['APPROVAL_PENDING', 'ACTIVE'], true)) {
        throw new RuntimeException("Abbonamento PayPal in stato inatteso: $status");
    }

    $conn = getDBConnection();

    // Ottieni il plan_id per "Pro" dal DB
    $stm_plan = mysqli_prepare($conn, "SELECT id FROM saas_plans WHERE LOWER(name)='pro' LIMIT 1");
    mysqli_stmt_execute($stm_plan);
    $row_plan = mysqli_fetch_assoc(mysqli_stmt_get_result($stm_plan));
    $pro_plan_id = (int)($row_plan['id'] ?? 2);

    $now        = date('Y-m-d H:i:s');
    $period_end = date('Y-m-d', strtotime('+1 month'));

    // Aggiorna la subscription esistente del tenant
    $stm_upd = mysqli_prepare($conn,
        "UPDATE saas_subscriptions
         SET plan_id=?, status='active', paypal_subscription_id=?,
             current_period_start=?, current_period_end=?,
             trial_ends_at=NULL, cancelled_at=NULL
         WHERE tenant_id=?");
    mysqli_stmt_bind_param($stm_upd, 'isssi', $pro_plan_id, $subscription_id, $now, $period_end, $tenant_id);
    if (!mysqli_stmt_execute($stm_upd)) {
        throw new RuntimeException('Errore aggiornamento DB: ' . mysqli_error($conn));
    }

    // Ottieni ID subscription per FK
    $stm_sid = mysqli_prepare($conn, 'SELECT id FROM saas_subscriptions WHERE tenant_id=? LIMIT 1');
    mysqli_stmt_bind_param($stm_sid, 'i', $tenant_id);
    mysqli_stmt_execute($stm_sid);
    $row_sid = mysqli_fetch_assoc(mysqli_stmt_get_result($stm_sid));
    $sub_db_id = (int)($row_sid['id'] ?? 0);

    // Aggiorna anche saas_tenants.status
    $stm_tnt = mysqli_prepare($conn, "UPDATE saas_tenants SET status='active' WHERE id=?");
    mysqli_stmt_bind_param($stm_tnt, 'i', $tenant_id);
    mysqli_stmt_execute($stm_tnt);

    // Registra pagamento iniziale (se PayPal fornisce dati)
    $amount   = floatval($sub['billing_info']['last_payment']['amount']['value'] ?? 7.00);
    $tx_id    = $subscription_id . '_initial';
    $paid_now = date('Y-m-d H:i:s');

    if ($sub_db_id > 0) {
        // Evita duplicati
        $stm_chk = mysqli_prepare($conn, 'SELECT id FROM saas_payments WHERE provider_payment_id=?');
        mysqli_stmt_bind_param($stm_chk, 's', $tx_id);
        mysqli_stmt_execute($stm_chk);
        if (!mysqli_fetch_row(mysqli_stmt_get_result($stm_chk))) {
            $stm_ins = mysqli_prepare($conn,
                "INSERT INTO saas_payments
                     (tenant_id, subscription_id, amount, currency, payment_provider, provider_payment_id, status, paid_at)
                 VALUES (?, ?, ?, 'EUR', 'paypal', ?, 'completed', ?)");
            mysqli_stmt_bind_param($stm_ins, 'iidss', $tenant_id, $sub_db_id, $amount, $tx_id, $paid_now);
            mysqli_stmt_execute($stm_ins);
        }
    }

    mysqli_close($conn);

    // Aggiorna sessione con nuovi dati piano
    refreshTenantSession(getDBConnection());

    set_flash('🎉 Abbonamento Pro attivato con successo! Benvenuto in fatturapp Pro.', 'success');
    header('Location: ' . APP_URL . 'saas/billing.php');
    exit;

} catch (\Throwable $e) {
    error_log('[PayPal] success.php error: ' . $e->getMessage());
    set_flash('Errore nell\'attivazione dell\'abbonamento: ' . $e->getMessage() . '. Contatta il supporto.', 'danger');
    header('Location: ' . APP_URL . 'saas/billing.php');
    exit;
}
