<?php
/**
 * Verifica indirizzo email tramite token.
 * GET ?token=<hex>
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$token  = trim($_GET['token'] ?? '');
$status = 'invalid'; // invalid | expired | already | ok

if (strlen($token) === 64) {
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        'SELECT id_utente, email_verified_at, verification_token_exp
         FROM tb_utenti
         WHERE verification_token = ? LIMIT 1'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $status = 'invalid';
    } elseif (!empty($row['email_verified_at'])) {
        $status = 'already';
    } elseif (strtotime($row['verification_token_exp']) < time()) {
        $status = 'expired';
    } else {
        $upd = $conn->prepare(
            'UPDATE tb_utenti
             SET email_verified_at = NOW(), verification_token = NULL, verification_token_exp = NULL
             WHERE id_utente = ?'
        );
        $upd->bind_param('i', $row['id_utente']);
        $upd->execute();
        $status = 'ok';
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verifica email — fatturapp</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php if ($status === 'ok'): ?>
<meta http-equiv="refresh" content="4;url=../login.php?verified=1">
<?php endif; ?>
<style>body{min-height:100vh;display:flex;align-items:center;justify-content:center}</style>
</head>
<body>
<div class="card border-0 shadow p-4 text-center" style="max-width:400px;width:100%;border-radius:1rem">
  <i class="bi bi-receipt-cutoff mb-3" style="font-size:2.2rem;color:#2563eb"></i>
  <?php if ($status === 'ok'): ?>
    <i class="bi bi-check-circle-fill text-success" style="font-size:2.8rem"></i>
    <h4 class="mt-3 fw-bold">Email verificata!</h4>
    <p class="text-muted">Il tuo account è attivo. Redirect al login in corso…</p>
    <a href="../login.php?verified=1" class="btn btn-primary mt-2">Accedi ora</a>
  <?php elseif ($status === 'already'): ?>
    <i class="bi bi-info-circle text-info" style="font-size:2.8rem"></i>
    <h4 class="mt-3 fw-bold">Già verificata</h4>
    <p class="text-muted">Questo indirizzo email è già stato verificato.</p>
    <a href="../login.php" class="btn btn-primary mt-2">Accedi</a>
  <?php elseif ($status === 'expired'): ?>
    <i class="bi bi-clock-history text-warning" style="font-size:2.8rem"></i>
    <h4 class="mt-3 fw-bold">Link scaduto</h4>
    <p class="text-muted">Il link di verifica è scaduto (valido 24 h). Registrati di nuovo.</p>
    <a href="register.php" class="btn btn-primary mt-2">Registrati di nuovo</a>
  <?php else: ?>
    <i class="bi bi-x-circle text-danger" style="font-size:2.8rem"></i>
    <h4 class="mt-3 fw-bold">Link non valido</h4>
    <p class="text-muted">Il link di verifica non è valido o è già stato utilizzato.</p>
    <a href="../login.php" class="btn btn-secondary mt-2">Torna al Login</a>
  <?php endif; ?>
</div>
</body>
</html>
