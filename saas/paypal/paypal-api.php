<?php
/**
 * PayPal REST API helper functions.
 * Requires constants from config.php: PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET,
 *                                     PAYPAL_API_BASE, PAYPAL_PLAN_ID_PRO.
 */

/**
 * Ottieni un access token OAuth2 da PayPal.
 * @throws RuntimeException se la richiesta fallisce
 */
function getPayPalToken(): string
{
    $ch = curl_init(PAYPAL_API_BASE . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || !$body) {
        throw new RuntimeException("PayPal token error (HTTP $http)");
    }
    $data = json_decode($body, true);
    if (empty($data['access_token'])) {
        throw new RuntimeException('PayPal token missing in response');
    }
    return $data['access_token'];
}

/**
 * Esegui una chiamata all'API PayPal.
 * @param string $method GET|POST|PATCH
 * @param string $endpoint path dopo PAYPAL_API_BASE (es. /v1/billing/subscriptions)
 * @param array  $payload  dati da inviare come JSON
 * @return array ['status' => int, 'body' => array]
 */
function paypalRequest(string $method, string $endpoint, array $payload = []): array
{
    $token = getPayPalToken();

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Prefer: return=representation',
    ];

    $ch = curl_init(PAYPAL_API_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if (!empty($payload)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $body  = curl_exec($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException("PayPal cURL error: $error");
    }

    return [
        'status' => $http,
        'body'   => json_decode($body ?: '{}', true),
    ];
}

/**
 * Crea un abbonamento PayPal per il piano Pro.
 * @param string $returnUrl URL di ritorno dopo l'approvazione
 * @param string $cancelUrl URL di ritorno in caso di cancellazione
 * @return array dati abbonamento PayPal (contiene 'id' e link 'approve')
 */
function createPayPalSubscription(string $returnUrl, string $cancelUrl): array
{
    $payload = [
        'plan_id'       => PAYPAL_PLAN_ID_PRO,
        'application_context' => [
            'brand_name'          => 'fatturapp',
            'locale'              => 'it-IT',
            'shipping_preference' => 'NO_SHIPPING',
            'user_action'         => 'SUBSCRIBE_NOW',
            'return_url'          => $returnUrl,
            'cancel_url'          => $cancelUrl,
        ],
    ];

    $res = paypalRequest('POST', '/v1/billing/subscriptions', $payload);
    if ($res['status'] !== 201) {
        throw new RuntimeException('Errore creazione abbonamento PayPal: HTTP ' . $res['status']);
    }
    return $res['body'];
}

/**
 * Ottieni i dettagli di un abbonamento PayPal per ID.
 */
function getPayPalSubscription(string $subscriptionId): array
{
    $res = paypalRequest('GET', '/v1/billing/subscriptions/' . urlencode($subscriptionId));
    if ($res['status'] !== 200) {
        throw new RuntimeException('Abbonamento PayPal non trovato: HTTP ' . $res['status']);
    }
    return $res['body'];
}

/**
 * Cancella un abbonamento PayPal.
 */
function cancelPayPalSubscription(string $subscriptionId, string $reason = 'Richiesta utente'): bool
{
    $res = paypalRequest('POST', '/v1/billing/subscriptions/' . urlencode($subscriptionId) . '/cancel', [
        'reason' => $reason,
    ]);
    return in_array($res['status'], [200, 204], true);
}

/**
 * Verifica la firma di un webhook PayPal.
 * @param array  $headers  headers HTTP della richiesta (chiavi uppercase, es. PAYPAL-TRANSMISSION-ID)
 * @param string $body     corpo grezzo della richiesta
 * @param string $webhookId ID del webhook configurato nel dashboard PayPal
 */
function verifyPayPalWebhook(array $headers, string $body, string $webhookId): bool
{
    $payload = [
        'auth_algo'         => $headers['PAYPAL-AUTH-ALGO']         ?? '',
        'cert_url'          => $headers['PAYPAL-CERT-URL']          ?? '',
        'transmission_id'   => $headers['PAYPAL-TRANSMISSION-ID']   ?? '',
        'transmission_sig'  => $headers['PAYPAL-TRANSMISSION-SIG']  ?? '',
        'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'] ?? '',
        'webhook_id'        => $webhookId,
        'webhook_event'     => json_decode($body, true),
    ];

    try {
        $res = paypalRequest('POST', '/v1/notifications/verify-webhook-signature', $payload);
        return ($res['body']['verification_status'] ?? '') === 'SUCCESS';
    } catch (\Throwable) {
        return false;
    }
}
