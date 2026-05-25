<?php
require_once 'auth.php';
require_once 'db.php';

if (!in_array($_SESSION['ruolo'] ?? '', ['admin', 'user'], true)) {
    header('Location: index.php'); exit;
}

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    $denominazione   = trim($_POST['denominazione'] ?? '');
    $nome            = trim($_POST['nome'] ?? '');
    $cognome         = trim($_POST['cognome'] ?? '');
    $indirizzo       = trim($_POST['indirizzo'] ?? '');
    $citta           = trim($_POST['citta'] ?? '');
    $provincia       = trim($_POST['provincia'] ?? '');
    $cap             = trim($_POST['cap'] ?? '');
    $partita_iva     = trim($_POST['partita_iva'] ?? '');
    $codice_fiscale  = trim($_POST['codice_fiscale'] ?? '');
    $PR              = trim($_POST['PR'] ?? '');
    $tasse_pct       = max(0.0, min(100.0, floatval($_POST['tasse_percentuale'] ?? TAX_PERCENTAGE)));

    if ($action === 'insert') {
        $stmt = mysqli_prepare($conn,
            'INSERT INTO tb_anagrafiche (denominazione, nome, cognome, indirizzo, citta, provincia, cap, partita_iva, codice_fiscale, PR, tasse_percentuale)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'ssssssssssd',
            $denominazione, $nome, $cognome, $indirizzo, $citta, $provincia, $cap, $partita_iva, $codice_fiscale, $PR, $tasse_pct);
        mysqli_stmt_execute($stmt) ? set_flash('Nuova P.IVA inserita con successo!', 'success') : set_flash('Errore durante l\'inserimento.', 'danger');
        mysqli_stmt_close($stmt);

    } elseif ($action === 'update') {
        $id = intval($_POST['id_anagrafica']);
        $stmt = mysqli_prepare($conn,
            'UPDATE tb_anagrafiche SET denominazione=?, nome=?, cognome=?, indirizzo=?, citta=?, provincia=?, cap=?, partita_iva=?, codice_fiscale=?, PR=?, tasse_percentuale=?
             WHERE id_anagrafica=?');
        mysqli_stmt_bind_param($stmt, 'ssssssssssdi',
            $denominazione, $nome, $cognome, $indirizzo, $citta, $provincia, $cap, $partita_iva, $codice_fiscale, $PR, $tasse_pct, $id);
        mysqli_stmt_execute($stmt) ? set_flash('Dati aggiornati con successo!', 'success') : set_flash('Errore durante l\'aggiornamento.', 'danger');
        mysqli_stmt_close($stmt);

    } elseif ($action === 'delete') {
        $id = intval($_POST['id_anagrafica']);
        $stmt_chk = mysqli_prepare($conn, 'SELECT COUNT(*) FROM tb_fatture WHERE anagrafica_id = ?');
        mysqli_stmt_bind_param($stmt_chk, 'i', $id);
        mysqli_stmt_execute($stmt_chk);
        $n = intval(mysqli_stmt_get_result($stmt_chk)->fetch_row()[0]);
        mysqli_stmt_close($stmt_chk);

        if ($n > 0) {
            set_flash("Impossibile eliminare: $n fatture collegate a questa P.IVA.", 'danger');
        } else {
            $stmt = mysqli_prepare($conn, 'DELETE FROM tb_anagrafiche WHERE id_anagrafica = ?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt) ? set_flash('Anagrafica eliminata.', 'success') : set_flash('Errore eliminazione.', 'danger');
            mysqli_stmt_close($stmt);
        }
    }

    header('Location: gestione_piva.php');
    exit;
}

$anagrafiche = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM tb_anagrafiche ORDER BY denominazione'), MYSQLI_ASSOC);
mysqli_close($conn);

$page_title  = 'Gestione P.IVA';
$current_page = 'gestione_piva.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="page-content">
    <h2 class="mb-4"><i class="bi bi-building text-success me-2"></i>Gestione Anagrafiche (P.IVA)</h2>

    <!-- Form Inserimento / Modifica -->
    <div class="card page-card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i><span id="formTitle">Aggiungi P.IVA</span></h5>
        </div>
        <div class="card-body">
            <form method="POST" id="formAnagrafica">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="formAction" value="insert">
                <input type="hidden" name="id_anagrafica" id="anagraficaId" value="">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Denominazione / Ragione Sociale <span class="text-danger">*</span></label>
                        <input type="text" name="denominazione" id="inputDenominazione" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Nome</label>
                        <input type="text" name="nome" id="inputNome" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Cognome</label>
                        <input type="text" name="cognome" id="inputCognome" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Indirizzo</label>
                        <input type="text" name="indirizzo" id="inputIndirizzo" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Città</label>
                        <input type="text" name="citta" id="inputCitta" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Prov.</label>
                        <input type="text" name="provincia" id="inputProvincia" class="form-control" maxlength="2">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">CAP</label>
                        <input type="text" name="cap" id="inputCap" class="form-control" maxlength="5">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Partita IVA <span class="text-danger">*</span></label>
                        <input type="text" name="partita_iva" id="inputPiva" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Codice Fiscale</label>
                        <input type="text" name="codice_fiscale" id="inputCF" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Codice PR</label>
                        <input type="text" name="PR" id="inputPR" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold text-danger">Tasse % <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="tasse_percentuale" id="inputTasse" class="form-control"
                                   step="0.01" min="0" max="100" value="<?= TAX_PERCENTAGE ?>" required>
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">Usato per calcolare il netto.</div>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">
                        <i class="bi bi-x-circle me-1"></i> Annulla
                    </button>
                    <button type="submit" class="btn btn-success" id="btnSubmit">
                        <i class="bi bi-save me-1"></i> Salva P.IVA
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Elenco -->
    <div class="card page-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Elenco P.IVA Registrate</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Denominazione</th>
                            <th>P.IVA / CF</th>
                            <th>Sede</th>
                            <th>Codice PR</th>
                            <th class="text-center">Tasse %</th>
                            <th width="110" class="text-center">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($anagrafiche)): ?>
                            <?php foreach ($anagrafiche as $row): ?>
                            <tr>
                                <td><strong><?= e($row['denominazione']) ?></strong><br>
                                    <small class="text-muted"><?= e($row['nome']) ?> <?= e($row['cognome']) ?></small></td>
                                <td><?= e($row['partita_iva']) ?><br>
                                    <small class="text-muted"><?= e($row['codice_fiscale']) ?></small></td>
                                <td><?= e($row['citta']) ?> (<?= e($row['provincia']) ?>)</td>
                                <td><?= e($row['PR']) ?: '<span class="text-muted">—</span>' ?></td>
                                <td class="text-center">
                                    <span class="badge bg-info text-dark"><?= number_format($row['tasse_percentuale'] ?? TAX_PERCENTAGE, 1) ?>%</span>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick='modifica(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                            title="Modifica">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="confirmDelete(<?= $row['id_anagrafica'] ?>, '<?= e($row['denominazione']) ?>')"
                                            title="Elimina">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">Nessuna P.IVA registrata</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Elimina -->
<div class="modal fade" id="modalDelete" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0"><h6 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Elimina P.IVA</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body py-1"><p class="mb-0">Eliminare <strong id="deleteNome"></strong>?</p></div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id_anagrafica" id="deleteId">
                    <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function modifica(data) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('anagraficaId').value = data.id_anagrafica;
    document.getElementById('formTitle').textContent = 'Modifica P.IVA';
    document.getElementById('btnSubmit').textContent = 'Aggiorna P.IVA';
    document.getElementById('btnSubmit').className = 'btn btn-warning';

    const fields = {
        inputDenominazione: 'denominazione', inputNome: 'nome', inputCognome: 'cognome',
        inputIndirizzo: 'indirizzo', inputCitta: 'citta', inputProvincia: 'provincia',
        inputCap: 'cap', inputPiva: 'partita_iva', inputCF: 'codice_fiscale',
        inputPR: 'PR', inputTasse: 'tasse_percentuale'
    };
    for (const [id, key] of Object.entries(fields)) {
        const el = document.getElementById(id);
        if (el) el.value = data[key] ?? (id === 'inputTasse' ? '<?= TAX_PERCENTAGE ?>' : '');
    }
    document.getElementById('formAnagrafica').scrollIntoView({behavior: 'smooth'});
}

function resetForm() {
    document.getElementById('formAnagrafica').reset();
    document.getElementById('formAction').value = 'insert';
    document.getElementById('anagraficaId').value = '';
    document.getElementById('formTitle').textContent = 'Aggiungi P.IVA';
    document.getElementById('btnSubmit').textContent = 'Salva P.IVA';
    document.getElementById('btnSubmit').className = 'btn btn-success';
    document.getElementById('inputTasse').value = '<?= TAX_PERCENTAGE ?>';
}

function confirmDelete(id, nome) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteNome').textContent = nome;
    new bootstrap.Modal(document.getElementById('modalDelete')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
