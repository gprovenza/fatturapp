<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id'])) { header('Location: ../index.php'); exit; }

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim(strtolower($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Inserisci un indirizzo email valido.';
    } else {
        $conn  = getDBConnection();
        $stmt  = $conn->prepare('SELECT id_utente FROM tb_utenti WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row   = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $token    = bin2hex(random_bytes(32));
            $tokenExp = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $upd = $conn->prepare(
                'UPDATE tb_utenti SET reset_token = ?, reset_token_exp = ? WHERE id_utente = ?'
            );
            $upd->bind_param('ssi', $token, $tokenExp, $row['id_utente']);
            $upd->execute();

            $link = APP_URL . 'saas/reset-password.php?token=' . urlencode($token);
            sendMail($email, 'Reset password fatturapp', mailPasswordResetHtml($link));
        }
        // Risposta identica per prevenire user enumeration
        $sent = true;
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Password dimenticata — fatturapp</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>body{min-height:100vh;display:flex;align-items:center;justify-content:center}</style>
</head>
<body>
<div class="card border-0 shadow p-4" style="max-width:380px;width:100%;border-radius:1rem">
  <div class="text-center mb-4">
    <i class="bi bi-receipt-cutoff" style="font-size:2.2rem;color:#2563eb"></i>
    <h5 class="mt-2 fw-bold">Password dimenticata</h5>
    <p class="text-muted small mb-0">Inserisci la tua email e ti inviamo il link di reset</p>
  </div>
  <?php if ($sent): ?>
    <div class="alert alert-success small">
      <i class="bi bi-envelope-check me-1"></i>
      Se l'email è registrata, riceverai le istruzioni di reset entro pochi minuti.
    </div>
    <a href="../login.php" class="btn btn-primary w-100">Torna al Login</a>
  <?php else: ?>
    <?php if ($error): ?><div class="alert alert-danger small"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="POST" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label fw-semibold small">Email</label>
        <input type="email" name="email" class="form-control" required autofocus
               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
        <i class="bi bi-send me-1"></i> Invia link di reset
      </button>
    </form>
    <div class="text-center mt-3 small">
      <a href="../login.php" class="text-decoration-none">← Torna al login</a>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
