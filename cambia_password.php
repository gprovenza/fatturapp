<?php
require_once 'auth.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $password_attuale  = $_POST['password_attuale']  ?? '';
    $password_nuova    = $_POST['password_nuova']    ?? '';
    $password_conferma = $_POST['password_conferma'] ?? '';

    if (empty($password_attuale) || empty($password_nuova) || empty($password_conferma)) {
        set_flash('Tutti i campi sono obbligatori.', 'danger');
    } elseif ($password_nuova !== $password_conferma) {
        set_flash('Le nuove password non corrispondono.', 'danger');
    } elseif (strlen($password_nuova) < 8) {
        set_flash('La nuova password deve essere di almeno 8 caratteri.', 'danger');
    } else {
        $conn = getDBConnection();

        $stmt = $conn->prepare("SELECT password_hash FROM tb_utenti WHERE id_utente = ?");
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($password_attuale, $user['password_hash'])) {
            set_flash('La password attuale non è corretta.', 'danger');
        } else {
            $nuovo_hash = password_hash($password_nuova, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE tb_utenti SET password_hash = ? WHERE id_utente = ?");
            $stmt->bind_param('si', $nuovo_hash, $_SESSION['user_id']);
            if ($stmt->execute()) {
                set_flash('Password modificata con successo!', 'success');
            } else {
                set_flash('Errore durante l\'aggiornamento della password.', 'danger');
            }
            $stmt->close();
        }
        $conn->close();
    }

    header('Location: cambia_password.php');
    exit;
}

$page_title   = 'Cambia Password';
$current_page = 'cambia_password.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="page-content">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">

            <div class="page-header">
                <h4><i class="bi bi-key text-primary me-2"></i>Cambia Password</h4>
                <p class="page-subtitle">Modifica la password del tuo account.</p>
            </div>

            <div class="card page-card">
                <div class="card-body p-4">
                    <form method="POST" action="cambia_password.php" novalidate>
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="password_attuale">Password attuale</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" id="password_attuale" name="password_attuale"
                                       class="form-control" required autocomplete="current-password">
                                <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="password_attuale" tabindex="-1">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="password_nuova">Nuova password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" id="password_nuova" name="password_nuova"
                                       class="form-control" required minlength="8" autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="password_nuova" tabindex="-1">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">Minimo 8 caratteri.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="password_conferma">Conferma nuova password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" id="password_conferma" name="password_conferma"
                                       class="form-control" required minlength="8" autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="password_conferma" tabindex="-1">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Aggiorna Password
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary">Annulla</a>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<?php
$extra_scripts = <<<'JS'
<script>
document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.target);
        const icon  = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'bi bi-eye';
        }
    });
});
</script>
JS;
require_once 'includes/footer.php';
?>
