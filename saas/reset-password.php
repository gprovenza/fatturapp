<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/csrf.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id'])) { header('Location: ../index.php'); exit; }

$token  = trim($_GET['token'] ?? '');
$status = 'form'; // form | invalid | expired | done
$error  = '';

if (strlen($token) !== 64) {
    $status = 'invalid';
} else {
    $conn  = getDBConnection();
    $stmt  = $conn->prepare(
        'SELECT id_utente, reset_token_exp FROM tb_utenti WHERE reset_token = ? LIMIT 1'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $status = 'invalid';
    } elseif (strtotime($row['reset_token_exp']) < time()) {
        $status = 'expired';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $pw  = $_POST['password']         ?? '';
        $pw2 = $_POST['password_confirm'] ?? '';

        if (strlen($pw) < 8) {
            $error = 'La password deve avere almeno 8 caratteri.';
        } elseif ($pw !== $pw2) {
            $error = 'Le password non coincidono.';
        } else {
            $hash = password_hash($pw, PASSWORD_BCRYPT);
            $upd  = $conn->prepare(
                'UPDATE tb_utenti
                 SET password_hash = ?, reset_token = NULL, reset_token_exp = NULL
                 WHERE id_utente = ?'
            );
            $upd->bind_param('si', $hash, $row['id_utente']);
            $upd->execute();
            $status = 'done';
        }
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reimposta password — fatturapp</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php if ($status === 'done'): ?><meta http-equiv="refresh" content="4;url=../login.php"><?php endif; ?>
<style>body{min-height:100vh;display:flex;align-items:center;justify-content:center}</style>
</head>
<body>
<div class="card border-0 shadow p-4" style="max-width:380px;width:100%;border-radius:1rem">
  <div class="text-center mb-3">
    <i class="bi bi-receipt-cutoff" style="font-size:2.2rem;color:#2563eb"></i>
    <h5 class="mt-2 fw-bold">Reimposta password</h5>
  </div>

  <?php if ($status === 'done'): ?>
    <div class="text-center"><i class="bi bi-check-circle-fill text-success" style="font-size:2.5rem"></i>
    <p class="mt-3">Password aggiornata! Redirect al login…</p>
    <a href="../login.php" class="btn btn-primary">Accedi ora</a></div>
  <?php elseif ($status === 'expired'): ?>
    <div class="alert alert-warning small">Il link è scaduto (valido 1 ora). Richiedine uno nuovo.</div>
    <a href="forgot-password.php" class="btn btn-primary w-100">Nuovo link</a>
  <?php elseif ($status === 'invalid'): ?>
    <div class="alert alert-danger small">Link non valido o già utilizzato.</div>
    <a href="../login.php" class="btn btn-secondary w-100">Torna al login</a>
  <?php else: ?>
    <?php if ($error): ?><div class="alert alert-danger small"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="POST" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
      <!-- mantieni token in URL anche in POST -->
      <div class="mb-3">
        <label class="form-label fw-semibold small">Nuova password</label>
        <input type="password" name="password" id="pw" class="form-control" required minlength="8" autofocus>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold small">Conferma password</label>
        <input type="password" name="password_confirm" class="form-control" required minlength="8">
      </div>
      <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
        <i class="bi bi-shield-check me-1"></i> Salva nuova password
      </button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
