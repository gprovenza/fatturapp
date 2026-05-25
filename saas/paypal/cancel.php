<?php
/**
 * PayPal ritorna qui se l'utente annulla il processo di pagamento.
 */
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../config.php';

// Pulisci variabili di sessione temporanee
unset($_SESSION['paypal_subscription_id'], $_SESSION['paypal_tenant_id']);

set_flash('Pagamento annullato. Puoi riprovare in qualsiasi momento.', 'warning');
header('Location: ' . APP_URL . 'saas/plans.php');
exit;
