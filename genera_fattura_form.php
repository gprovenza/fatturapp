<?php
require_once 'auth.php';
require_once 'db.php';

// Solo admin e user
if (!in_array($_SESSION['ruolo'] ?? '', ['admin', 'user'], true)) {
    header('Location: index.php');
    exit;
}

$conn = getDBConnection();

$anagrafiche = mysqli_fetch_all(mysqli_query($conn, 'SELECT id_anagrafica, denominazione FROM tb_anagrafiche ORDER BY denominazione'), MYSQLI_ASSOC);
$clienti     = mysqli_fetch_all(mysqli_query($conn, 'SELECT id_cliente, denominazione FROM tb_clienti ORDER BY denominazione'), MYSQLI_ASSOC);

// Recupero progetti con cliente per dropdown
$result_prj = mysqli_query($conn,
    'SELECT p.id_progetto, p.nome_progetto, c.denominazione AS cliente_nome
     FROM tb_progetti p
     JOIN tb_clienti c ON p.id_cliente = c.id_cliente
     ORDER BY c.denominazione, p.nome_progetto');
$progetti = mysqli_fetch_all($result_prj, MYSQLI_ASSOC);

mysqli_close($conn);

$page_title  = 'Genera Pro-Forma';
$current_page = 'genera_fattura_form.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="page-content">
    <h2 class="mb-4"><i class="bi bi-file-earmark-plus text-success me-2"></i>Genera Fattura Pro-Forma</h2>

    <!-- Suggerimento ore -->
    <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <i class="bi bi-info-circle me-1"></i>
            <strong>Suggerimento:</strong> Consulta le ore registrate prima di compilare la fattura.
        </div>
        <button type="button" class="btn btn-sm btn-primary" onclick="apriRiepilogoOre()">
            <i class="bi bi-clock-history me-1"></i> Visualizza Ore Registrate
        </button>
    </div>

    <div class="card page-card">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Dati Fattura</h5>
        </div>
        <div class="card-body">
            <form action="genera_fattura.php" method="POST" target="_blank" id="formFattura">
                <?= csrf_field() ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Denominazione P.IVA <span class="text-danger">*</span></label>
                        <select name="anagrafica_id" class="form-select" required>
                            <option value="">Seleziona...</option>
                            <?php foreach ($anagrafiche as $row): ?>
                            <option value="<?= $row['id_anagrafica'] ?>"><?= e($row['denominazione']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Cliente <span class="text-danger">*</span></label>
                        <select name="cliente_id" class="form-select" required>
                            <option value="">Seleziona...</option>
                            <?php foreach ($clienti as $row): ?>
                            <option value="<?= $row['id_cliente'] ?>"><?= e($row['denominazione']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Mese <span class="text-danger">*</span></label>
                        <select name="mese" id="select_mese" class="form-select" required>
                            <option value="">Seleziona...</option>
                            <?php
                            $mesi_nomi = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
                            $mese_prec = ((int)date('n') - 2 + 12) % 12; // indice 0-based del mese precedente
                            foreach ($mesi_nomi as $idx => $nome_mese):
                                $sel = ($idx === $mese_prec) ? 'selected' : '';
                            ?>
                            <option value="<?= e($nome_mese) ?>" <?= $sel ?>><?= e($nome_mese) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Anno <span class="text-danger">*</span></label>
                        <select name="anno" id="select_anno" class="form-select" required>
                            <?php for ($y = date('Y'); $y <= date('Y') + 2; $y++):
                                $sel = ($y == date('Y')) ? 'selected' : '';
                            ?>
                            <option value="<?= $y ?>" <?= $sel ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <!-- Righe progetti dinamiche -->
                <h6 class="fw-semibold mb-3"><i class="bi bi-briefcase me-2 text-muted"></i>Progetti e Ore</h6>
                <div id="progetti-container">
                    <div class="row g-2 mb-2 progetto-row align-items-end">
                        <div class="col-md-7">
                            <label class="form-label">Progetto <span class="text-danger">*</span></label>
                            <select name="progetto_id[]" class="form-select" required>
                                <option value="">Seleziona...</option>
                                <?php foreach ($progetti as $prj): ?>
                                <option value="<?= $prj['id_progetto'] ?>">
                                    <?= e($prj['cliente_nome']) ?> — <?= e($prj['nome_progetto']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Ore erogate <span class="text-danger">*</span></label>
                            <input type="number" name="ore_erogate[]" class="form-control" required min="0.1" step="0.1" placeholder="es. 40">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-success w-100 add-progetto">
                                <i class="bi bi-plus-lg"></i> Aggiungi
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-success btn-lg flex-grow-1">
                        <i class="bi bi-file-earmark-pdf me-2"></i>Genera e Salva Fattura
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary btn-lg">Annulla</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Template riga progetto (senza label sulle successive righe per compattezza)
const progettiOptions = `<?php foreach ($progetti as $prj): ?><option value="<?= $prj['id_progetto'] ?>"><?= e($prj['cliente_nome']) ?> — <?= e($prj['nome_progetto']) ?></option><?php endforeach; ?>`;

document.getElementById('progetti-container').addEventListener('click', function(e) {
    const container = this;

    if (e.target.closest('.add-progetto')) {
        e.preventDefault();
        const newRow = document.createElement('div');
        newRow.className = 'row g-2 mb-2 progetto-row align-items-end';
        newRow.innerHTML = `
            <div class="col-md-7">
                <select name="progetto_id[]" class="form-select" required>
                    <option value="">Seleziona...</option>
                    ${progettiOptions}
                </select>
            </div>
            <div class="col-md-3">
                <input type="number" name="ore_erogate[]" class="form-control" required min="0.1" step="0.1" placeholder="es. 40">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-danger w-100 remove-progetto">
                    <i class="bi bi-dash-lg"></i> Rimuovi
                </button>
            </div>`;
        container.appendChild(newRow);
    }

    if (e.target.closest('.remove-progetto')) {
        e.preventDefault();
        const rows = container.querySelectorAll('.progetto-row');
        if (rows.length > 1) {
            e.target.closest('.progetto-row').remove();
        }
    }
});

// Apri popup riepilogo ore
function apriRiepilogoOre() {
    const meseMap = {
        'Gennaio':1,'Febbraio':2,'Marzo':3,'Aprile':4,'Maggio':5,'Giugno':6,
        'Luglio':7,'Agosto':8,'Settembre':9,'Ottobre':10,'Novembre':11,'Dicembre':12
    };
    const meseNome = document.getElementById('select_mese').value;
    const anno     = document.getElementById('select_anno').value;
    if (!meseNome || !anno) {
        showToast('Seleziona prima Mese e Anno.', 'warning');
        return;
    }
    const meseNum = meseMap[meseNome];
    window.open('riepilogo_ore.php?mese=' + meseNum + '&anno=' + anno, 'RiepilogoOre', 'width=960,height=720,scrollbars=yes');
}

// Redirect dopo submit (apre PDF in nuova tab, poi torna all'archivio)
document.getElementById('formFattura').addEventListener('submit', function() {
    setTimeout(function() {
        window.location.href = 'visualizza_fatture.php';
    }, 1500);
});
</script>

<?php require_once 'includes/footer.php'; ?>
