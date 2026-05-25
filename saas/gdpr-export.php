<?php
/**
 * GDPR Art. 20 — Esportazione dati utente in formato JSON.
 * Esporta tutti i dati del tenant corrente: anagrafiche, clienti, progetti,
 * fatture, ore lavorate.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/tenant.php';

$conn      = getDBConnection();
$tenant_id = getTenantId();
$user_id   = (int)($_SESSION['user_id'] ?? 0);

// Raccolta dati
$export = [
    'generated_at' => date('c'),
    'tenant_id'    => $tenant_id,
    'user_id'      => $user_id,
    'data'         => [],
];

// Utente
$stm = mysqli_prepare($conn, 'SELECT id_utente, username, email, ruolo, data_creazione FROM tb_utenti WHERE id_utente = ?');
mysqli_stmt_bind_param($stm, 'i', $user_id);
mysqli_stmt_execute($stm);
$export['data']['utente'] = mysqli_fetch_assoc(mysqli_stmt_get_result($stm)) ?: [];

// Anagrafiche P.IVA
$stm = mysqli_prepare($conn, 'SELECT * FROM tb_anagrafiche WHERE tenant_id = ?');
mysqli_stmt_bind_param($stm, 'i', $tenant_id);
mysqli_stmt_execute($stm);
$export['data']['anagrafiche'] = mysqli_fetch_all(mysqli_stmt_get_result($stm), MYSQLI_ASSOC);

// Clienti
$stm = mysqli_prepare($conn, 'SELECT * FROM tb_clienti WHERE tenant_id = ?');
mysqli_stmt_bind_param($stm, 'i', $tenant_id);
mysqli_stmt_execute($stm);
$export['data']['clienti'] = mysqli_fetch_all(mysqli_stmt_get_result($stm), MYSQLI_ASSOC);

// Progetti
$stm = mysqli_prepare($conn, 'SELECT * FROM tb_progetti WHERE tenant_id = ?');
mysqli_stmt_bind_param($stm, 'i', $tenant_id);
mysqli_stmt_execute($stm);
$export['data']['progetti'] = mysqli_fetch_all(mysqli_stmt_get_result($stm), MYSQLI_ASSOC);

// Fatture
$stm = mysqli_prepare($conn, 'SELECT * FROM tb_fatture WHERE tenant_id = ?');
mysqli_stmt_bind_param($stm, 'i', $tenant_id);
mysqli_stmt_execute($stm);
$export['data']['fatture'] = mysqli_fetch_all(mysqli_stmt_get_result($stm), MYSQLI_ASSOC);

// Ore lavorate
$stm = mysqli_prepare($conn, 'SELECT * FROM tb_ore_lavoro WHERE tenant_id = ? AND user_id = ?');
mysqli_stmt_bind_param($stm, 'ii', $tenant_id, $user_id);
mysqli_stmt_execute($stm);
$export['data']['ore_lavoro'] = mysqli_fetch_all(mysqli_stmt_get_result($stm), MYSQLI_ASSOC);

mysqli_close($conn);

// Registra la richiesta export nella tabella GDPR
// (facoltativo — non blocca il download se fallisce)
try {
    $conn2 = getDBConnection();
    $stm2  = mysqli_prepare($conn2,
        "INSERT INTO saas_gdpr_exports (tenant_id, requested_by, status, completed_at)
         VALUES (?, ?, 'ready', NOW())");
    mysqli_stmt_bind_param($stm2, 'ii', $tenant_id, $user_id);
    mysqli_stmt_execute($stm2);
    mysqli_close($conn2);
} catch (\Throwable) {
    // non fatale
}

// Invia il file JSON
$filename = 'fatturapp_export_' . date('Ymd_His') . '.json';
header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
