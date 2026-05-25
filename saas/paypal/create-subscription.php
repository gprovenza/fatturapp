<?php
/**
 * Avvia il flusso di abbonamento PayPal.
 * Crea un abbonamento PayPal e reindirizza l'utente alla pagina di approvazione.
 */
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/paypal-api.php';

$tenant_id = getTenantId();

// Impedisce doppio abbonamento
$plan = getTenantPlan();
if (strtolower($plan['name'] ?? '') === 'pro' && $plan['status'] === 'active') {
    set_flash('Hai già un abbonamento Pro attivo.', 'info');
    header('Location: ' . APP_URL . 'saas/billing.php');
    exit;
}

// URL di ritorno
$returnUrl = APP_URL . 'saas/paypal/success.php';
$cancelUrl = APP_URL . 'saas/paypal/cancel.php';

try {
    if (empty(PAYPAL_CLIENT_ID) || empty(PAYPAL_CLIENT_SECRET) || empty(PAYPAL_PLAN_ID_PRO)) {
        throw new RuntimeException('PayPal non configurato. Contatta il supporto.');
    }

    $subscription = createPayPalSubscription($returnUrl, $cancelUrl);

    // Trova il link di approvazione
    $approveUrl = '';
    foreach ($subscription['links'] ?? [] as $link) {
        if ($link['rel'] === 'approve') {
            $approveUrl = $link['href'];
            break;
        }
    }

    if (empty($approveUrl)) {
        throw new RuntimeException('Link approvazione PayPal non trovato.');
    }

    // Salva l'ID abbonamento PayPal in sessione per verifica al ritorno
    $_SESSION['paypal_subscription_id'] = $subscription['id'];
    $_SESSION['paypal_tenant_id']       = $tenant_id;

    header('Location: ' . $approveUrl);
    exit;

} catch (\Throwable $e) {
    error_log('[PayPal] create-subscription error: ' . $e->getMessage());
    set_flash('Errore nell\'avvio del pagamento: ' . $e->getMessage(), 'danger');
    header('Location: ' . APP_URL . 'saas/plans.php');
    exit;
}
