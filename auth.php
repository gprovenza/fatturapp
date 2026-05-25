<?php
/**
 * Autenticazione base: verifica sessione attiva, gestisce timeout.
 * Include questo file in ogni pagina protetta (qualsiasi ruolo).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/alerts.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verifica autenticazione e timeout sessione
require_auth();
