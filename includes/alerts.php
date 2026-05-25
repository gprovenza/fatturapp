<?php
/**
 * Sistema di flash messages (toast notifications)
 *
 * Uso:
 *   set_flash('Operazione completata', 'success');
 *   set_flash('Errore durante il salvataggio', 'danger');
 *
 * I messaggi vengono mostrati automaticamente da includes/footer.php
 * tramite Bootstrap Toast.
 */

/**
 * Salva un flash message nella sessione.
 *
 * @param string $message Testo del messaggio
 * @param string $type    Tipo Bootstrap: success | danger | warning | info
 */
function set_flash(string $message, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_messages'][] = [
        'message' => $message,
        'type'    => $type,
    ];
}

/**
 * Recupera e svuota i flash messages dalla sessione.
 *
 * @return array
 */
function get_flash_messages(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}
