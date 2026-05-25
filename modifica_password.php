<?php
require_once 'auth.php';
require_once 'db.php';

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $password_attuale = $_POST['password_attuale'];
    $password_nuova = $_POST['password_nuova'];
    $password_conferma = $_POST['password_conferma'];
    
    // Validazione
    if (empty($password_attuale) || empty($password_nuova) || empty($password_conferma)) {
        $message = "Tutti i campi sono obbligatori";
        $message_type = "danger";
    } elseif ($password_nuova !== $password_conferma) {
        $message = "Le nuove password non corrispondono";
        $message_type = "danger";
    } elseif (strlen($password_nuova) < 6) {
        $message = "La nuova password deve essere di almeno 6 caratteri";
        $message_type = "danger";
    } else {
        $conn = getDBConnection();
        
        // Verifica password attuale
        $stmt = mysqli_prepare($conn, "SELECT password_hash FROM tb_utenti WHERE id_utente = ?");
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        if (!password_verify($password_attuale, $user['password_hash'])) {
            $message = "La password attuale non è corretta";
            $message_type = "danger";
        } else {
            // Aggiorna password
            $nuovo_hash = password_hash($password_nuova, PASSWORD_DEFAULT);
            
            $stmt = mysqli_prepare($conn, "UPDATE tb_utenti SET password_hash = ? WHERE id_utente = ?");
            mysqli_stmt_bind_param($stmt, "si", $nuovo_hash, $_SESSION['user_id']);
            
            if (mysqli_stmt_execute($stmt)) {
                $message = "Password modificata con successo!";
                $message_type = "success";
            } else {
                $message = "Errore durante l'aggiornamento: " . mysqli_error($conn);
                $message_type = "danger";
            }
            mysqli_stmt_close($stmt);
        }
        
        mysqli_close($conn);
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifica Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">← Torna alla Home</a>
            <span class="text-white">
                Benvenuto, <?= htmlspecialchars($_SESSION['username']) ?>
                <a href="logout.php" class="btn btn-sm btn-light ms-3">Logout</a>
            </span>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="bi bi-key"></i> Modifica Password</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
                                <?= htmlspecialchars($message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Password Attuale</label>
                                <input type="password" name="password_attuale" class="form-control" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Nuova Password</label>
                                <input type="password" name="password_nuova" class="form-control" required minlength="6">
                                <small class="form-text text-muted">Minimo 6 caratteri</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Conferma Nuova Password</label>
                                <input type="password" name="password_conferma" class="form-control" required minlength="6">
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i> Cambia Password
                                </button>
                                <a href="index.php" class="btn btn-secondary">Annulla</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
