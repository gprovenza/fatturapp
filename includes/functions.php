<?php
/**
 * Funzioni helper condivise dell'applicazione
 */

/**
 * Formatta un importo in euro.
 */
function formatCurrency(float $amount): string {
    return '€ ' . number_format($amount, 2, ',', '.');
}

/**
 * Formatta una data dal formato Y-m-d al formato d/m/Y.
 */
function formatDate(?string $date): string {
    if (empty($date)) return '-';
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt ? $dt->format('d/m/Y') : htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
}

/**
 * Nomi dei mesi in italiano (1=Gennaio ... 12=Dicembre).
 */
function getNomeMese(int $mese): string {
    $mesi = [
        1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
        5 => 'Maggio',  6 => 'Giugno',   7 => 'Luglio', 8 => 'Agosto',
        9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
    ];
    return $mesi[$mese] ?? (string)$mese;
}

/**
 * Calcola il guadagno netto applicando la percentuale tasse.
 */
function calcolaNetto(float $lordo, float $tasse_percentuale): float {
    return $lordo * (1 - $tasse_percentuale / 100);
}

/**
 * Sanitizza una stringa per output HTML.
 */
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Verifica che l'utente abbia il ruolo richiesto.
 * Reindirizza a login.php se non autenticato, a index.php se non autorizzato.
 *
 * @param string|array $ruoli Ruolo o array di ruoli ammessi
 */
function require_role($ruoli): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    $ruoli = (array)$ruoli;
    if (!in_array($_SESSION['ruolo'] ?? '', $ruoli, true)) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Verifica che l'utente sia autenticato (qualsiasi ruolo).
 */
function require_auth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    // Session timeout: 30 minuti di inattività
    $timeout = 1800;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        header('Location: login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}
