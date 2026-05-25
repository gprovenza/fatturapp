<?php
/**
 * Cancella l'abbonamento PayPal dell'utente corrente.
 * L'accesso Pro rimane attivo fino alla fine del periodo già pagato.
 */
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/paypal-api.php';

csrf_verify();

$tenant_id = getTenantId();

$conn = getDBConnection();

// Legge il paypal_subscription_id dal DB
$stm = mysqli_prepare($conn, 'SELECT paypal_subscription_id, current_period_end FROM saas_subscriptions WHERE tenant_id = ? AND status = "active"');
mysqli_stmt_bind_param($stm, 'i', $tenant_id);
mysqli_stmt_execute($stm);
$sub = mysqli_fetch_assoc(mysqli_stmt_get_result($stm));

if (!$sub || empty($sub['paypal_subscription_id'])) {
    set_flash('Nessun abbonamento attivo da cancellare.', 'warning');
    header('Location: ' . APP_URL . 'saas/billing.php');
    exit;
}

try {
    // Cancella su PayPal
    cancelPayPalSubscription($sub['paypal_subscription_id'], 'Richiesta utente tramite portale');

    // Marca come cancellato nel DB (mantieni period_end per accesso residuo)
    $now = date('Y-m-d H:i:s');
    $stm_upd = mysqli_prepare($conn,
        'UPDATE saas_subscriptions SET status="cancelled", cancelled_at=? WHERE tenant_id=?');
    mysqli_stmt_bind_param($stm_upd, 'si', $now, $tenant_id);
    mysqli_stmt_execute($stm_upd);

    mysqli_close($conn);

    // Aggiorna sessione
    refreshTenantSession(getDBConnection());

    $end_date = !empty($sub['current_period_end'])
        ? date('d/m/Y', strtotime($sub['current_period_end']))
        : 'fine periodo';

    set_flash("Abbonamento cancellato. Mantieni l'accesso Pro fino al $end_date.", 'success');
    header('Location: ' . APP_URL . 'saas/billing.php');
    exit;

} catch (\Throwable $e) {
    error_log('[PayPal] cancel-subscription error: ' . $e->getMessage());
    set_flash('Errore nella cancellazione: ' . $e->getMessage() . '. Contatta il supporto.', 'danger');
    header('Location: ' . APP_URL . 'saas/billing.php');
    exit;
}
