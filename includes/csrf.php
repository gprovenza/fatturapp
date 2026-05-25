<?php
/**
 * CSRF Protection helpers
 */

/**
 * Genera o recupera il CSRF token dalla sessione.
 */
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Ritorna l'input hidden HTML con il CSRF token.
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verifica il CSRF token dal POST.
 * In caso di fallimento termina l'esecuzione con HTTP 403.
 */
function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Richiesta non valida (CSRF token mancante o errato). <a href="javascript:history.back()">Torna indietro</a>');
    }
}
