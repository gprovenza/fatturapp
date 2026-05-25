<?php
/**
 * PayPal Webhook handler.
 * Gestisce gli eventi:
 *   BILLING.SUBSCRIPTION.ACTIVATED  → attiva abbonamento
 *   PAYMENT.SALE.COMPLETED           → registra pagamento
 *   BILLING.SUBSCRIPTION.CANCELLED  → cancella abbonamento
 *   BILLING.SUBSCRIPTION.EXPIRED    → scade abbonamento
 *   BILLING.SUBSCRIPTION.SUSPENDED  → sospende abbonamento
 *
 * Configura questo URL nel PayPal Developer Dashboard.
 * ENV richiesti: PAYPAL_WEBHOOK_ID
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/paypal-api.php';

// Rispondi subito con 200 OK per evitare retry PayPal
http_response_code(200);
header('Content-Type: application/json');

$raw = file_get_contents('php://input');
if (empty($raw)) {
    error_log('[Webhook] Empty body');
    exit;
}

// Costruisci array headers
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $hk = str_replace('_', '-', substr($k, 5));
        $headers[$hk] = $v;
    }
}
// Apache/PHP può passarli anche senza HTTP_ prefix per alcuni
foreach (['PAYPAL-AUTH-ALGO','PAYPAL-CERT-URL','PAYPAL-TRANSMISSION-ID','PAYPAL-TRANSMISSION-SIG','PAYPAL-TRANSMISSION-TIME'] as $hname) {
    $sKey = str_replace('-', '_', $hname);
    if (isset($_SERVER[$sKey])) $headers[$hname] = $_SERVER[$sKey];
}

// Verifica firma se PAYPAL_WEBHOOK_ID configurato
$webhookId = getenv('PAYPAL_WEBHOOK_ID') ?: '';
if (!empty($webhookId) && PAYPAL_MODE !== 'sandbox') {
    if (!verifyPayPalWebhook($headers, $raw, $webhookId)) {
        error_log('[Webhook] Firma non valida');
        exit;
    }
}

$event = json_decode($raw, true);
if (!$event || empty($event['event_type'])) {
    error_log('[Webhook] JSON non valido o event_type mancante');
    exit;
}

$eventType     = $event['event_type'];
$resource      = $event['resource'] ?? [];
$subscriptionId = $resource['id'] ?? $resource['billing_agreement_id'] ?? '';

if (empty($subscriptionId)) {
    error_log("[Webhook] $eventType senza subscription_id");
    exit;
}

$conn = getDBConnection();

// Recupera tenant dal paypal_subscription_id
$stm_find = mysqli_prepare($conn, 'SELECT tenant_id FROM saas_subscriptions WHERE paypal_subscription_id = ? LIMIT 1');
mysqli_stmt_bind_param($stm_find, 's', $subscriptionId);
mysqli_stmt_execute($stm_find);
$row_sub = mysqli_fetch_assoc(mysqli_stmt_get_result($stm_find));

if (!$row_sub && !in_array($eventType, ['BILLING.SUBSCRIPTION.ACTIVATED'], true)) {
    error_log("[Webhook] tenant non trovato per subscription $subscriptionId (evento: $eventType)");
    mysqli_close($conn);
    exit;
}

$tenant_id = (int)($row_sub['tenant_id'] ?? 0);
$now = date('Y-m-d H:i:s');

switch ($eventType) {

    case 'BILLING.SUBSCRIPTION.ACTIVATED':
        // Potrebbe arrivare prima del success.php — crea/aggiorna record
        if (!$row_sub) {
            error_log("[Webhook] ACTIVATED ma tenant non trovato per $subscriptionId — skip");
            break;
        }
        $period_end = date('Y-m-d H:i:s', strtotime('+1 month'));
        $stm = mysqli_prepare($conn,
            "UPDATE saas_subscriptions
             SET status='active', current_period_start=?, current_period_end=?, trial_ends_at=NULL, cancelled_at=NULL
             WHERE tenant_id=?");
        mysqli_stmt_bind_param($stm, 'ssi', $now, $period_end, $tenant_id);
        mysqli_stmt_execute($stm);

        // Aggiorna tenant
        $stm2 = mysqli_prepare($conn, "UPDATE saas_tenants SET status='active' WHERE id=?");
        mysqli_stmt_bind_param($stm2, 'i', $tenant_id);
        mysqli_stmt_execute($stm2);
        error_log("[Webhook] ACTIVATED tenant=$tenant_id sub=$subscriptionId");
        break;

    case 'PAYMENT.SALE.COMPLETED':
        // Pagamento mensile ricevuto → rinnova periodo e registra pagamento
        $amount   = floatval($resource['amount']['total'] ?? 7.00);
        $tx_id    = $resource['id'] ?? uniqid('paypal_');
        $period_end = date('Y-m-d', strtotime('+1 month'));

        $stm = mysqli_prepare($conn,
            "UPDATE saas_subscriptions
             SET status='active', current_period_start=?, current_period_end=?, cancelled_at=NULL
             WHERE tenant_id=?");
        mysqli_stmt_bind_param($stm, 'ssi', $now, $period_end, $tenant_id);
        mysqli_stmt_execute($stm);

        // Ottieni subscription_id per FK
        $stm_sid = mysqli_prepare($conn, 'SELECT id FROM saas_subscriptions WHERE tenant_id=? LIMIT 1');
        mysqli_stmt_bind_param($stm_sid, 'i', $tenant_id);
        mysqli_stmt_execute($stm_sid);
        $row_sid   = mysqli_fetch_row(mysqli_stmt_get_result($stm_sid));
        $sub_db_id = (int)($row_sid[0] ?? 0);

        // Evita duplicati
        $stm_chk = mysqli_prepare($conn, 'SELECT id FROM saas_payments WHERE provider_payment_id=?');
        mysqli_stmt_bind_param($stm_chk, 's', $tx_id);
        mysqli_stmt_execute($stm_chk);
        if (!mysqli_fetch_row(mysqli_stmt_get_result($stm_chk)) && $sub_db_id > 0) {
            $paid_now = date('Y-m-d H:i:s');
            $stm_ins  = mysqli_prepare($conn,
                "INSERT INTO saas_payments
                     (tenant_id, subscription_id, amount, currency, payment_provider, provider_payment_id, status, paid_at)
                 VALUES (?, ?, ?, 'EUR', 'paypal', ?, 'completed', ?)");
            mysqli_stmt_bind_param($stm_ins, 'iidss', $tenant_id, $sub_db_id, $amount, $tx_id, $paid_now);
            mysqli_stmt_execute($stm_ins);
        }
        error_log("[Webhook] PAYMENT.SALE.COMPLETED tenant=$tenant_id amount=$amount");
        break;

    case 'BILLING.SUBSCRIPTION.CANCELLED':
    case 'BILLING.SUBSCRIPTION.SUSPENDED':
        $stm = mysqli_prepare($conn,
            "UPDATE saas_subscriptions SET status='cancelled', cancelled_at=? WHERE tenant_id=?");
        mysqli_stmt_bind_param($stm, 'si', $now, $tenant_id);
        mysqli_stmt_execute($stm);
        error_log("[Webhook] $eventType tenant=$tenant_id");
        break;

    case 'BILLING.SUBSCRIPTION.EXPIRED':
        $stm = mysqli_prepare($conn,
            "UPDATE saas_subscriptions SET status='expired' WHERE tenant_id=?");
        mysqli_stmt_bind_param($stm, 'i', $tenant_id);
        mysqli_stmt_execute($stm);

        // Downgrade a Free
        $stm2 = mysqli_prepare($conn, "SELECT id FROM saas_plans WHERE LOWER(name)='free' LIMIT 1");
        mysqli_stmt_execute($stm2);
        $row_free = mysqli_fetch_assoc(mysqli_stmt_get_result($stm2));
        if ($row_free) {
            $free_id = (int)$row_free['id'];
            $stm3 = mysqli_prepare($conn, "UPDATE saas_subscriptions SET plan_id=? WHERE tenant_id=?");
            mysqli_stmt_bind_param($stm3, 'ii', $free_id, $tenant_id);
            mysqli_stmt_execute($stm3);
        }
        error_log("[Webhook] EXPIRED tenant=$tenant_id");
        break;

    default:
        error_log("[Webhook] Evento non gestito: $eventType");
}

mysqli_close($conn);
echo json_encode(['ok' => true]);
