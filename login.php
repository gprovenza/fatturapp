<?php
require_once 'config.php';
require_once 'db.php';
require_once 'includes/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Già loggato → redirect
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Timeout notifica
$timeout_msg = isset($_GET['timeout']) ? 'Sessione scaduta per inattività. Esegui nuovamente il login.' : '';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    csrf_verify();

    // Rate limiting: max 5 tentativi per sessione
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0);
    if ($_SESSION['login_attempts'] >= 5) {
        $error = 'Troppi tentativi di accesso. Ricarica la pagina e riprova.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($username) && !empty($password)) {
            $conn = getDBConnection();
            $stmt = mysqli_prepare($conn, 'SELECT id_utente, password_hash, ruolo FROM tb_utenti WHERE username = ?');
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if ($row = mysqli_fetch_assoc($result)) {
                if (password_verify($password, $row['password_hash'])) {
                    // Login corretto: rigenera session ID per prevenire fixation
                    session_regenerate_id(true);
                    $_SESSION['user_id']         = $row['id_utente'];
                    $_SESSION['username']        = $username;
                    $_SESSION['ruolo']           = $row['ruolo'];
                    $_SESSION['last_activity']   = time();
                    unset($_SESSION['login_attempts']);
                    mysqli_stmt_close($stmt);
                    mysqli_close($conn);
                    header('Location: index.php');
                    exit;
                }
            }

            $_SESSION['login_attempts']++;
            $error = 'Credenziali non valide.';
            mysqli_stmt_close($stmt);
            mysqli_close($conn);
        } else {
            $error = 'Inserisci username e password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Fatturazione</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script>
        (function() {
            const t = localStorage.getItem('fatturazione-theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { width: 100%; max-width: 380px; border: none; border-radius: 1rem; box-shadow: 0 4px 24px rgba(0,0,0,0.12); }
        .login-logo { font-size: 2.5rem; color: #0d6efd; }
    </style>
</head>
<body>
    <div class="login-card card p-4">
        <div class="text-center mb-4">
            <i class="bi bi-receipt-cutoff login-logo"></i>
            <h4 class="mt-2 fw-bold">Fatturazione</h4>
            <p class="text-muted mb-0" style="font-size:0.85rem;">Accedi al tuo account</p>
        </div>

        <?php if ($timeout_msg): ?>
            <div class="alert alert-warning py-2"><i class="bi bi-clock me-1"></i> <?= htmlspecialchars($timeout_msg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" name="username" class="form-control" required autofocus
                           value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="password" class="form-control" required>
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePassword()" tabindex="-1">
                        <i class="bi bi-eye" id="eye-icon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                <i class="bi bi-box-arrow-in-right me-1"></i> Accedi
            </button>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword() {
            const p = document.getElementById('password');
            const i = document.getElementById('eye-icon');
            if (p.type === 'password') {
                p.type = 'text';
                i.className = 'bi bi-eye-slash';
            } else {
                p.type = 'password';
                i.className = 'bi bi-eye';
            }
        }
    </script>
</body>
</html>
