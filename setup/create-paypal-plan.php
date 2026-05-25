#!/usr/bin/env php
<?php
/**
 * fatturapp — Setup PayPal: crea prodotto + piano ricorrente
 * ─────────────────────────────────────────────────────────────
 * Eseguire UNA SOLA VOLTA da terminale (non via browser):
 *
 *   cd /var/www/html/fatturazione
 *   php setup/create-paypal-plan.php
 *
 * Dopo l'esecuzione copia il PLAN_ID mostrato in .env:
 *   PAYPAL_PLAN_ID_PRO=P-XXXXXXXXXXXXXXXXXXXXXXXXX
 * ─────────────────────────────────────────────────────────────
 */

// Carica config senza avviare sessione
define('CLI_MODE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../saas/paypal/paypal-api.php';

// ── Colori ANSI ───────────────────────────────────────────────
function ok(string $msg): void  { echo "\033[32m✔ $msg\033[0m\n"; }
function err(string $msg): void { echo "\033[31m✖ $msg\033[0m\n"; exit(1); }
function info(string $msg): void{ echo "\033[36mℹ $msg\033[0m\n"; }
function bold(string $msg): void{ echo "\033[1m$msg\033[0m\n"; }

bold("\nfatturapp — PayPal Setup\n");
info('Modalità: ' . PAYPAL_MODE);
info('API Base: ' . PAYPAL_API_BASE);

if (empty(PAYPAL_CLIENT_ID) || empty(PAYPAL_CLIENT_SECRET)) {
    err('PAYPAL_CLIENT_ID e PAYPAL_CLIENT_SECRET mancanti in .env');
}

// ── 1. Test autenticazione ─────────────────────────────────────
echo "\n[1/4] Test autenticazione PayPal...\n";
try {
    $token = getPayPalToken();
    ok("Token ottenuto (" . substr($token, 0, 20) . "...)");
} catch (\Throwable $e) {
    err("Autenticazione fallita: " . $e->getMessage());
}

// ── 2. Crea prodotto ──────────────────────────────────────────
echo "\n[2/4] Creazione prodotto...\n";
try {
    $res = paypalRequest('POST', '/v1/catalogs/products', [
        'name'        => 'fatturapp Pro',
        'description' => 'Piano professionale per la gestione fatture forfettarie',
        'type'        => 'SERVICE',
        'category'    => 'SOFTWARE',
    ]);

    if (!in_array($res['status'], [200, 201], true)) {
        // Potrebbe già esistere — cerca il primo prodotto esistente
        info("Creazione prodotto fallita (HTTP {$res['status']}), cerco prodotto esistente...");
        $listRes = paypalRequest('GET', '/v1/catalogs/products?page_size=5');
        $products = $listRes['body']['products'] ?? [];
        if (empty($products)) {
            err("Nessun prodotto trovato e creazione fallita.");
        }
        $productId = $products[0]['id'];
        ok("Usando prodotto esistente: $productId ({$products[0]['name']})");
    } else {
        $productId = $res['body']['id'];
        ok("Prodotto creato: $productId");
    }
} catch (\Throwable $e) {
    err("Errore prodotto: " . $e->getMessage());
}

// ── 3. Crea piano ricorrente ───────────────────────────────────
echo "\n[3/4] Creazione piano ricorrente (€7/mese)...\n";
try {
    $planPayload = [
        'product_id'  => $productId,
        'name'        => 'fatturapp Pro — Mensile',
        'description' => 'Abbonamento mensile fatturapp Pro · Disdici in qualsiasi momento',
        'status'      => 'ACTIVE',
        'billing_cycles' => [
            [
                'frequency'     => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'tenure_type'   => 'REGULAR',
                'sequence'      => 1,
                'total_cycles'  => 0, // 0 = infinito
                'pricing_scheme'=> ['fixed_price' => ['value' => '7.00', 'currency_code' => 'EUR']],
            ],
        ],
        'payment_preferences' => [
            'auto_bill_outstanding'     => true,
            'setup_fee'                 => ['value' => '0', 'currency_code' => 'EUR'],
            'setup_fee_failure_action'  => 'CONTINUE',
            'payment_failure_threshold' => 3,
        ],
    ];

    $res = paypalRequest('POST', '/v1/billing/plans', $planPayload);

    if (!in_array($res['status'], [200, 201], true)) {
        err("Creazione piano fallita (HTTP {$res['status']}): " . json_encode($res['body']));
    }

    $planId = $res['body']['id'];
    ok("Piano creato: $planId");

} catch (\Throwable $e) {
    err("Errore piano: " . $e->getMessage());
}

// ── 4. Output finale ──────────────────────────────────────────
echo "\n[4/4] Configurazione completata!\n\n";
bold("══════════════════════════════════════════════");
bold("  Copia questo valore nel tuo file .env:");
bold("══════════════════════════════════════════════");
echo "\n\033[33mPAYPAL_PLAN_ID_PRO=$planId\033[0m\n\n";

info("Prossimo passo: configura il webhook PayPal.");
info("URL webhook da registrare su developer.paypal.com:");
echo "\n\033[33m" . APP_URL . "saas/paypal/webhook.php\033[0m\n\n";

info("Eventi da abilitare sul webhook:");
$events = [
    'BILLING.SUBSCRIPTION.ACTIVATED',
    'BILLING.SUBSCRIPTION.CANCELLED',
    'BILLING.SUBSCRIPTION.EXPIRED',
    'BILLING.SUBSCRIPTION.SUSPENDED',
    'PAYMENT.SALE.COMPLETED',
];
foreach ($events as $e) {
    echo "  · $e\n";
}
echo "\n";
