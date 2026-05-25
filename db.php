<?php
require_once __DIR__ . '/config.php';

/**
 * Crea e restituisce una connessione al database.
 * In caso di errore termina silenziosamente (nessun messaggio all'utente).
 */
function getDBConnection(): mysqli {
    $conn = @mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if (!$conn) {
        error_log('Errore connessione DB: ' . mysqli_connect_error());
        die('Impossibile connettersi al database. Contattare l\'amministratore.');
    }
    mysqli_set_charset($conn, 'utf8mb4');
    return $conn;
}
