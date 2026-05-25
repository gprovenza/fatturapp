<?php
require_once 'auth_admin.php';
require_once 'db.php';

$conn = getDBConnection();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $prefisso = mysqli_real_escape_string($conn, $_POST['prefisso_fattura']);
    $progressivo = intval($_POST['progressivo_fattura']);
    
    // Aggiorna prefisso
    $stmt = mysqli_prepare($conn, "UPDATE tb_impostazioni SET valore = ? WHERE chiave = 'prefisso_fattura'");
    mysqli_stmt_bind_param($stmt, "s", $prefisso);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    // Aggiorna progressivo
    $stmt = mysqli_prepare($conn, "UPDATE tb_impostazioni SET valore = ? WHERE chiave = 'progressivo_fattura'");
    mysqli_stmt_bind_param($stmt, "i", $progressivo);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    $message = "Impostazioni salvate con successo!";
    $message_type = "success";
}

// Recupera impostazioni correnti
$query = "SELECT chiave, valore FROM tb_impostazioni";
$result = mysqli_query($conn, $query);
$impostazioni = [];
while ($row = mysqli_fetch_assoc($result)) {
    $impostazioni[$row['chiave']] = $row['valore'];
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impostazioni Sistema</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">← Torna alla Home</a>
            <span class="text-white">
                Benvenuto, <?= htmlspecialchars($_SESSION['username']) ?> <span class="badge bg-danger">ADMIN</span>
                <a href="logout.php" class="btn btn-sm btn-light ms-3">Logout</a>
            </span>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="bi bi-gear"></i> Impostazioni Sistema</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
                                <?= htmlspecialchars($message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <h5 class="mb-3">Numerazione Fatture</h5>
                            
                            <div class="mb-3">
                                <label class="form-label">Prefisso Fattura</label>
                                <input type="text" name="prefisso_fattura" class="form-control" 
                                       value="<?= htmlspecialchars($impostazioni['prefisso_fattura']) ?>" 
                                       required maxlength="10">
                                <small class="form-text text-muted">
                                    Es: DOC, FATTURA, INV, ecc.
                                </small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Numero Progressivo Attuale</label>
                                <input type="number" name="progressivo_fattura" class="form-control" 
                                       value="<?= htmlspecialchars($impostazioni['progressivo_fattura']) ?>" 
                                       required min="0">
                                <small class="form-text text-muted">
                                    La prossima fattura sarà: <strong><?= htmlspecialchars($impostazioni['prefisso_fattura']) ?><?= intval($impostazioni['progressivo_fattura']) + 1 ?>-<?= date('Y') ?></strong>
                                </small>
                            </div>
                            
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> 
                                <strong>Attenzione:</strong> Modificare il progressivo potrebbe creare duplicati. 
                                Usa solo se sai cosa stai facendo.
                            </div>
                            
                            <hr>
                            
                            <h5 class="mb-3">Informazioni Anno Corrente</h5>
                            <div class="mb-3">
                                <label class="form-label">Anno Progressivo</label>
                                <input type="text" class="form-control" 
                                       value="<?= htmlspecialchars($impostazioni['anno_progressivo']) ?>" 
                                       disabled readonly>
                                <small class="form-text text-muted">
                                    Il progressivo si resetta automaticamente ogni anno
                                </small>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Salva Impostazioni
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
