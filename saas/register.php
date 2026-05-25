<?php
/**
 * Registrazione nuovo utente fatturapp SaaS.
 * Crea: tb_utenti + saas_tenants + saas_subscriptions (trial 30gg) + saas_tenant_settings.
 * Invia email di verifica.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Già loggato → redirect
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php'); exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email    = trim(strtolower($_POST['email']    ?? ''));
    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    // Validazione
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Indirizzo email non valido.';
    } elseif (strlen($password) < 8) {
        $error = 'La password deve avere almeno 8 caratteri.';
    } elseif ($password !== $confirm) {
        $error = 'Le password non coincidono.';
    } else {
        $conn = getDBConnection();

        // Verifica email già registrata
        $stmtChk = $conn->prepare('SELECT id_utente FROM tb_utenti WHERE email = ? LIMIT 1');
        $stmtChk->bind_param('s', $email);
        $stmtChk->execute();
        if ($stmtChk->get_result()->num_rows > 0) {
            $error = 'Esiste già un account con questa email. <a href="forgot-password.php">Password dimenticata?</a>';
        } else {
            // Genera username univoco dalla parte locale dell'email
            $base     = preg_replace('/[^a-z0-9]/', '', explode('@', $email)[0]);
            $base     = substr($base ?: 'user', 0, 40);
            $username = $base;
            $suffix   = 1;
            while (true) {
                $stmtUn = $conn->prepare('SELECT COUNT(*) FROM tb_utenti WHERE username = ?');
                $stmtUn->bind_param('s', $username);
                $stmtUn->execute();
                if ((int)$stmtUn->get_result()->fetch_row()[0] === 0) break;
                $username = $base . $suffix++;
            }

            // Token verifica email (scade in 24 h)
            $token    = bin2hex(random_bytes(32));
            $tokenExp = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $passHash = password_hash($password, PASSWORD_BCRYPT);

            $conn->begin_transaction();
            try {
                // 1 — Crea utente
                $stmtU = $conn->prepare(
                    'INSERT INTO tb_utenti
                       (username, password_hash, ruolo, email, verification_token, verification_token_exp)
                     VALUES (?, ?, \'user\', ?, ?, ?)'
                );
                $stmtU->bind_param('sssss', $username, $passHash, $email, $token, $tokenExp);
                $stmtU->execute();
                $userId = (int)$conn->insert_id;

                // 2 — Crea tenant
                $stmtT = $conn->prepare(
                    'INSERT INTO saas_tenants (owner_user_id) VALUES (?)'
                );
                $stmtT->bind_param('i', $userId);
                $stmtT->execute();
                $tenantId = (int)$conn->insert_id;

                // 3 — Collega utente al tenant
                $stmtLink = $conn->prepare('UPDATE tb_utenti SET tenant_id = ? WHERE id_utente = ?');
                $stmtLink->bind_param('ii', $tenantId, $userId);
                $stmtLink->execute();

                // 4 — Abbonamento: piano Pro in trial 30 gg
                $planStmt = $conn->prepare('SELECT id, trial_days FROM saas_plans WHERE name = \'pro\' LIMIT 1');
                $planStmt->execute();
                $plan     = $planStmt->get_result()->fetch_assoc();
                $planId   = (int)$plan['id'];
                $trialEnd = date('Y-m-d H:i:s', strtotime('+' . (int)$plan['trial_days'] . ' days'));

                $stmtS = $conn->prepare(
                    'INSERT INTO saas_subscriptions
                       (tenant_id, plan_id, status, trial_ends_at, payment_provider)
                     VALUES (?, ?, \'trial\', ?, \'none\')'
                );
                $stmtS->bind_param('iis', $tenantId, $planId, $trialEnd);
                $stmtS->execute();

                // 5 — Copia settings di default per il nuovo tenant
                $anno = (int)date('Y');
                $stmtSt = $conn->prepare(
                    'INSERT INTO saas_tenant_settings (tenant_id, chiave, valore) VALUES (?, ?, ?)'
                );
                foreach ([
                    'prefisso_fattura'    => 'DOC',
                    'progressivo_fattura' => '1',
                    'anno_progressivo'    => (string)$anno,
                ] as $k => $v) {
                    $stmtSt->bind_param('iss', $tenantId, $k, $v);
                    $stmtSt->execute();
                }

                $conn->commit();

                // 6 — Invia email di verifica
                $verifyLink = APP_URL . 'saas/verify.php?token=' . urlencode($token);
                $sent = sendMail($email, 'Verifica il tuo account fatturapp', mailVerificationHtml($verifyLink));
                if (!$sent) {
                    error_log("Verifica email non inviata a {$email}");
                }

                $success = true;
            } catch (\Throwable $e) {
                $conn->rollback();
                error_log('Errore registrazione: ' . $e->getMessage());
                $error = 'Errore interno durante la registrazione. Riprova tra qualche minuto.';
            }
        }
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registrati gratis — fatturapp</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script>(function(){const t=localStorage.getItem('fatturazione-theme')||'light';document.documentElement.setAttribute('data-bs-theme',t);})();</script>
<style>
  body { min-height:100vh; display:flex; align-items:center; justify-content:center; background:var(--bs-body-bg); }
  .reg-card { width:100%; max-width:420px; border:none; border-radius:1rem; box-shadow:0 4px 24px rgba(0,0,0,.12); }
</style>
</head>
<body>
<div class="reg-card card p-4">
  <?php if ($success): ?>
    <div class="text-center py-3">
      <i class="bi bi-envelope-check text-success" style="font-size:3rem"></i>
      <h4 class="mt-3 fw-bold">Controlla la tua email!</h4>
      <p class="text-muted">Abbiamo inviato un link di verifica a <strong><?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>.<br>
      Clicca il link per attivare il trial gratuito di 30 giorni.</p>
      <a href="../login.php" class="btn btn-primary mt-2">Vai al Login</a>
    </div>
  <?php else: ?>
    <div class="text-center mb-4">
      <i class="bi bi-receipt-cutoff" style="font-size:2.5rem;color:#2563eb"></i>
      <h4 class="mt-2 fw-bold">fatturapp</h4>
      <p class="text-muted mb-0" style="font-size:.85rem;">Trial gratuito 30 giorni · Nessuna carta richiesta</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2 small"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <?= csrf_field() ?>

      <div class="mb-3">
        <label class="form-label fw-semibold small">Email</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-envelope"></i></span>
          <input type="email" name="email" class="form-control" required autofocus
                 placeholder="tu@esempio.it"
                 value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold small">Password <span class="text-muted fw-normal">(min. 8 caratteri)</span></label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock"></i></span>
          <input type="password" name="password" id="pw" class="form-control" required minlength="8">
          <button type="button" class="btn btn-outline-secondary" onclick="togglePw('pw','eye1')" tabindex="-1">
            <i class="bi bi-eye" id="eye1"></i>
          </button>
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label fw-semibold small">Conferma password</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
          <input type="password" name="password_confirm" id="pw2" class="form-control" required minlength="8">
          <button type="button" class="btn btn-outline-secondary" onclick="togglePw('pw2','eye2')" tabindex="-1">
            <i class="bi bi-eye" id="eye2"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
        <i class="bi bi-rocket-takeoff me-1"></i> Inizia il trial gratuito
      </button>
    </form>

    <div class="text-center mt-3" style="font-size:.82rem;">
      Hai già un account? <a href="../login.php" class="text-decoration-none fw-semibold">Accedi</a>
    </div>

    <hr class="my-3">
    <div class="text-center text-muted" style="font-size:.75rem;">
      <i class="bi bi-shield-check text-success me-1"></i>Dati in UE &nbsp;·&nbsp;
      <i class="bi bi-credit-card-2-back text-muted me-1"></i>Nessuna carta · Cancel quando vuoi
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePw(id, iconId) {
  const f = document.getElementById(id);
  const i = document.getElementById(iconId);
  f.type = f.type === 'password' ? 'text' : 'password';
  i.className = f.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
</script>
</body>
</html>
