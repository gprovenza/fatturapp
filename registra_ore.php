<?php
require_once 'auth.php';
require_once 'db.php';

$conn = getDBConnection();

$message = '';
$message_type = '';

// Gestione inserimento/modifica/eliminazione
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $data = $_POST['data_lavoro'];
                $progetto_id = intval($_POST['progetto_id']);
                $tipo_ore = $_POST['tipo_ore'];
                $ore = floatval($_POST['ore']);
                $note = mysqli_real_escape_string($conn, $_POST['note']);
                $user_id = $_SESSION['user_id'];
                
                $stmt = mysqli_prepare($conn, 
                    "INSERT INTO tb_ore_lavoro (data_lavoro, progetto_id, tipo_ore, ore, note, user_id) 
                     VALUES (?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "sisssi", $data, $progetto_id, $tipo_ore, $ore, $note, $user_id);
                
                if (mysqli_stmt_execute($stmt)) {
                    $message = "Ore registrate con successo!";
                    $message_type = "success";
                } else {
                    $message = "Errore: " . mysqli_error($conn);
                    $message_type = "danger";
                }
                mysqli_stmt_close($stmt);
                break;
                
            case 'edit':
                $id = intval($_POST['id_ore']);
                $data = $_POST['data_lavoro'];
                $progetto_id = intval($_POST['progetto_id']);
                $tipo_ore = $_POST['tipo_ore'];
                $ore = floatval($_POST['ore']);
                $note = mysqli_real_escape_string($conn, $_POST['note']);
                
                $stmt = mysqli_prepare($conn,
                    "UPDATE tb_ore_lavoro SET data_lavoro=?, progetto_id=?, tipo_ore=?, ore=?, note=? 
                     WHERE id_ore=? AND user_id=?");
                mysqli_stmt_bind_param($stmt, "sissii", $data, $progetto_id, $tipo_ore, $ore, $note, $id, $_SESSION['user_id']);
                
                if (mysqli_stmt_execute($stmt)) {
                    $message = "Ore modificate con successo!";
                    $message_type = "success";
                } else {
                    $message = "Errore: " . mysqli_error($conn);
                    $message_type = "danger";
                }
                mysqli_stmt_close($stmt);
                break;
                
            case 'delete':
                $id = intval($_POST['id_ore']);
                $stmt = mysqli_prepare($conn, "DELETE FROM tb_ore_lavoro WHERE id_ore = ? AND user_id = ?");
                mysqli_stmt_bind_param($stmt, "ii", $id, $_SESSION['user_id']);
                
                if (mysqli_stmt_execute($stmt)) {
                    $message = "Ore eliminate con successo!";
                    $message_type = "success";
                } else {
                    $message = "Errore: " . mysqli_error($conn);
                    $message_type = "danger";
                }
                mysqli_stmt_close($stmt);
                break;
        }
    }
}

// Filtri per mese/anno
$mese_selezionato = isset($_GET['mese']) ? intval($_GET['mese']) : date('n');
$anno_selezionato = isset($_GET['anno']) ? intval($_GET['anno']) : date('Y');

// Recupera progetti
$query_progetti = "SELECT id_progetto, nome_progetto, paga_oraria, tariffa_gruppo FROM tb_progetti ORDER BY nome_progetto";
$result_progetti = mysqli_query($conn, $query_progetti);
$progetti = [];
while ($row = mysqli_fetch_assoc($result_progetti)) {
    $progetti[$row['id_progetto']] = $row;
}

// Recupera ore del mese
$primo_giorno = "$anno_selezionato-" . str_pad($mese_selezionato, 2, '0', STR_PAD_LEFT) . "-01";
$ultimo_giorno = date("Y-m-t", strtotime($primo_giorno));

$query_ore = "SELECT o.*, p.nome_progetto, p.paga_oraria, p.tariffa_gruppo 
              FROM tb_ore_lavoro o
              JOIN tb_progetti p ON o.progetto_id = p.id_progetto
              WHERE o.user_id = {$_SESSION['user_id']}
              AND o.data_lavoro BETWEEN '$primo_giorno' AND '$ultimo_giorno'
              ORDER BY o.data_lavoro DESC, o.id_ore DESC";
$result_ore = mysqli_query($conn, $query_ore);

// Calcola totali
$totali = [];
$totale_ore_mese = 0;
$totale_lordo = 0;

mysqli_data_seek($result_ore, 0);
while ($row = mysqli_fetch_assoc($result_ore)) {
    $progetto_id = $row['progetto_id'];
    $ore = floatval($row['ore']);
    $tariffa = ($row['tipo_ore'] === 'gruppo') ? floatval($row['tariffa_gruppo']) : floatval($row['paga_oraria']);
    
    if (!isset($totali[$progetto_id])) {
        $totali[$progetto_id] = [
            'nome' => $row['nome_progetto'],
            'ore_singolo' => 0,
            'ore_gruppo' => 0,
            'totale_ore' => 0,
            'totale_lordo' => 0
        ];
    }
    
    if ($row['tipo_ore'] === 'gruppo') {
        $totali[$progetto_id]['ore_gruppo'] += $ore;
    } else {
        $totali[$progetto_id]['ore_singolo'] += $ore;
    }
    
    $totali[$progetto_id]['totale_ore'] += $ore;
    $totali[$progetto_id]['totale_lordo'] += ($ore * $tariffa);
    
    $totale_ore_mese += $ore;
    $totale_lordo += ($ore * $tariffa);
}

mysqli_data_seek($result_ore, 0);

$ritenute = 0.35;
$totale_netto = $totale_lordo * (1 - $ritenute);

$nomi_mesi = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
    5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
    9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
];
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrazione Ore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>
    <div class="content-wrapper">
        <nav class="navbar navbar-dark bg-primary">
            <div class="container-fluid">
                <a class="navbar-brand" href="index.php">← Torna alla Home</a>
                <span class="text-white">
                    Benvenuto, <?= htmlspecialchars($_SESSION['username']) ?>
                    <a href="logout.php" class="btn btn-sm btn-light ms-3">Logout</a>
                </span>
            </div>
        </nav>

        <div class="container mt-4">
            <h2 class="mb-4"><i class="bi bi-clock-history"></i> Registrazione Ore di Lavoro</h2>
            
            <?php if ($message): ?>
                <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Filtro Mese/Anno e Pulsanti -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <form method="GET" class="row g-2">
                        <div class="col-auto">
                            <select name="mese" class="form-select">
                                <?php foreach ($nomi_mesi as $num => $nome): ?>
                                    <option value="<?= $num ?>" <?= ($num == $mese_selezionato) ? 'selected' : '' ?>>
                                        <?= $nome ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <select name="anno" class="form-select">
                                <?php for ($i = 2024; $i <= date('Y') + 1; $i++): ?>
                                    <option value="<?= $i ?>" <?= ($i == $anno_selezionato) ? 'selected' : '' ?>>
                                        <?= $i ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Filtra
                            </button>
                        </div>
                    </form>
                </div>
                <div class="col-md-6 text-end">
                    <a href="export_ore_excel.php?mese=<?= $mese_selezionato ?>&anno=<?= $anno_selezionato ?>" 
                       class="btn btn-success me-2">
                        <i class="bi bi-file-earmark-excel"></i> Esporta Excel
                    </a>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdd">
                        <i class="bi bi-plus-circle"></i> Registra Ore
                    </button>
                </div>
            </div>

            <!-- Riepilogo Mensile -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-calendar-month"></i> Riepilogo <?= $nomi_mesi[$mese_selezionato] ?> <?= $anno_selezionato ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($totali) > 0): ?>
                                <div class="row">
                                    <?php foreach ($totali as $progetto_id => $totale_prog): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card border-primary">
                                                <div class="card-body">
                                                    <h6 class="card-title"><?= htmlspecialchars($totale_prog['nome']) ?></h6>
                                                    <p class="mb-1"><strong>Ore Singolo:</strong> <?= number_format($totale_prog['ore_singolo'], 2) ?> h</p>
                                                    <p class="mb-1"><strong>Ore Gruppo:</strong> <?= number_format($totale_prog['ore_gruppo'], 2) ?> h</p>
                                                    <p class="mb-1"><strong>Totale Ore:</strong> <?= number_format($totale_prog['totale_ore'], 2) ?> h</p>
                                                    <p class="mb-0"><strong>Lordo:</strong> € <?= number_format($totale_prog['totale_lordo'], 2, ',', '.') ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <hr>
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="alert alert-primary mb-0">
                                        <strong>Totale Ore:</strong><br>
                                        <h4><?= number_format($totale_ore_mese, 2) ?> h</h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="alert alert-success mb-0">
                                        <strong>Totale Lordo:</strong><br>
                                        <h4>€ <?= number_format($totale_lordo, 2, ',', '.') ?></h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="alert alert-warning mb-0">
                                        <strong>Ritenute (35%):</strong><br>
                                        <h4>€ <?= number_format($totale_lordo * $ritenute, 2, ',', '.') ?></h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="alert alert-info mb-0">
                                        <strong>Totale Netto:</strong><br>
                                        <h4>€ <?= number_format($totale_netto, 2, ',', '.') ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabella Dettaglio Ore -->
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0"><i class="bi bi-list-ul"></i> Dettaglio Ore Registrate</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Data</th>
                                    <th>Progetto</th>
                                    <th>Tipo</th>
                                    <th>Ore</th>
                                    <th>Tariffa</th>
                                    <th>Importo</th>
                                    <th>Note</th>
                                    <th width="100">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($result_ore) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($result_ore)): 
                                        $tariffa = ($row['tipo_ore'] === 'gruppo') ? floatval($row['tariffa_gruppo']) : floatval($row['paga_oraria']);
                                        $importo = floatval($row['ore']) * $tariffa;
                                    ?>
                                        <tr>
                                            <td><?= date('d/m/Y', strtotime($row['data_lavoro'])) ?></td>
                                            <td><?= htmlspecialchars($row['nome_progetto']) ?></td>
                                            <td>
                                                <?php if ($row['tipo_ore'] === 'gruppo'): ?>
                                                    <span class="badge bg-primary">Gruppo</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Singolo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= number_format($row['ore'], 2) ?> h</td>
                                            <td>€ <?= number_format($tariffa, 2, ',', '.') ?></td>
                                            <td>€ <?= number_format($importo, 2, ',', '.') ?></td>
                                            <td><?= htmlspecialchars($row['note']) ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-warning" onclick='editOre(<?= json_encode($row) ?>)'>
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteOre(<?= $row['id_ore'] ?>, '<?= date('d/m/Y', strtotime($row['data_lavoro'])) ?>')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center">Nessuna ora registrata per questo mese</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <!-- Modal Aggiungi -->
    <div class="modal fade" id="modalAdd" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Registra Ore di Lavoro</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Data</label>
                            <input type="date" name="data_lavoro" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Progetto</label>
                            <select name="progetto_id" class="form-select" id="add_progetto" required onchange="toggleTipoOre('add')">
                                <option value="">Seleziona...</option>
                                <?php foreach ($progetti as $id => $prog): ?>
                                    <option value="<?= $id ?>" 
                                            data-gruppo="<?= ($prog['tariffa_gruppo'] > 0) ? '1' : '0' ?>">
                                        <?= htmlspecialchars($prog['nome_progetto']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3" id="add_tipo_container" style="display:none;">
                            <label class="form-label">Tipo Ore</label>
                            <select name="tipo_ore" class="form-select" id="add_tipo">
                                <option value="singolo">Singolo</option>
                                <option value="gruppo">Gruppo</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ore</label>
                            <input type="number" name="ore" class="form-control" step="0.1" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note (opzionale)</label>
                            <textarea name="note" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-success">Salva</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modifica -->
    <div class="modal fade" id="modalEdit" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id_ore" id="edit_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Modifica Ore</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Data</label>
                            <input type="date" name="data_lavoro" id="edit_data" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Progetto</label>
                            <select name="progetto_id" class="form-select" id="edit_progetto" required onchange="toggleTipoOre('edit')">
                                <option value="">Seleziona...</option>
                                <?php foreach ($progetti as $id => $prog): ?>
                                    <option value="<?= $id ?>" 
                                            data-gruppo="<?= ($prog['tariffa_gruppo'] > 0) ? '1' : '0' ?>">
                                        <?= htmlspecialchars($prog['nome_progetto']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3" id="edit_tipo_container">
                            <label class="form-label">Tipo Ore</label>
                            <select name="tipo_ore" class="form-select" id="edit_tipo">
                                <option value="singolo">Singolo</option>
                                <option value="gruppo">Gruppo</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ore</label>
                            <input type="number" name="ore" id="edit_ore" class="form-control" step="0.1" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note</label>
                            <textarea name="note" id="edit_note" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-warning">Salva Modifiche</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Form nascosto per eliminazione -->
    <form method="POST" id="formDelete" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id_ore" id="delete_id">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleTipoOre(mode) {
            const select = document.getElementById(mode + '_progetto');
            const container = document.getElementById(mode + '_tipo_container');
            const selectedOption = select.options[select.selectedIndex];
            const hasGruppo = selectedOption.getAttribute('data-gruppo') === '1';
            
            if (hasGruppo) {
                container.style.display = 'block';
            } else {
                container.style.display = 'none';
                document.getElementById(mode + '_tipo').value = 'singolo';
            }
        }

        function editOre(data) {
            document.getElementById('edit_id').value = data.id_ore;
            document.getElementById('edit_data').value = data.data_lavoro;
            document.getElementById('edit_progetto').value = data.progetto_id;
            document.getElementById('edit_tipo').value = data.tipo_ore;
            document.getElementById('edit_ore').value = data.ore;
            document.getElementById('edit_note').value = data.note || '';
            
            toggleTipoOre('edit');
            
            new bootstrap.Modal(document.getElementById('modalEdit')).show();
        }

        function deleteOre(id, data) {
            if (confirm('Sei sicuro di voler eliminare le ore del ' + data + '?')) {
                document.getElementById('delete_id').value = id;
                document.getElementById('formDelete').submit();
            }
        }
    </script>
</body>
</html>

<?php mysqli_close($conn); ?>
