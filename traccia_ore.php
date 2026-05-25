<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'includes/tenant.php';

$conn      = getDBConnection();
$tenant_id = getTenantId();

// Inserimento nuova registrazione ore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'insert') {
    csrf_verify();

    $data        = $_POST['data'] ?? '';
    $progetto_id = intval($_POST['progetto_id'] ?? 0);
    $ore         = floatval($_POST['ore'] ?? 0);
    $note        = trim($_POST['note'] ?? '');

    if (!empty($data) && $progetto_id > 0 && $ore > 0) {
        requireActiveSub();
        $stmt = mysqli_prepare($conn, 'INSERT INTO tb_ore_lavoro (data_lavoro, progetto_id, ore, note, user_id, tipo_ore, tenant_id) VALUES (?, ?, ?, ?, ?, \'singolo\', ?)');
        mysqli_stmt_bind_param($stmt, 'sidsii', $data, $progetto_id, $ore, $note, $_SESSION['user_id'], $tenant_id);
        if (mysqli_stmt_execute($stmt)) {
            set_flash('Ore registrate con successo!', 'success');
        } else {
            set_flash('Errore durante il salvataggio. Riprova.', 'danger');
        }
        mysqli_stmt_close($stmt);
    } else {
        set_flash('Dati non validi. Verifica i campi e riprova.', 'warning');
    }

    $qs = http_build_query(['mese' => $_GET['mese'] ?? date('m'), 'anno' => $_GET['anno'] ?? date('Y'), 'progetto' => $_GET['progetto'] ?? '']);
    header("Location: traccia_ore.php?$qs");
    exit;
}

// Eliminazione registrazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();

    $id   = intval($_POST['id_ore'] ?? 0);
    $stmt = mysqli_prepare($conn, 'DELETE FROM tb_ore_lavoro WHERE id_ore = ? AND user_id = ? AND tenant_id = ?');
    mysqli_stmt_bind_param($stmt, 'iii', $id, $_SESSION['user_id'], $tenant_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    set_flash('Registrazione eliminata.', 'success');

    $qs = http_build_query(['mese' => $_GET['mese'] ?? date('m'), 'anno' => $_GET['anno'] ?? date('Y'), 'progetto' => $_GET['progetto'] ?? '', 'page' => $_GET['page'] ?? 1]);
    header("Location: traccia_ore.php?$qs");
    exit;
}

// Recupera progetti per i dropdown
$query_progetti = 'SELECT p.id_progetto, p.nome_progetto, c.denominazione AS cliente_nome
                   FROM tb_progetti p
                   JOIN tb_clienti c ON p.id_cliente = c.id_cliente
                   WHERE p.tenant_id = ?
                   ORDER BY c.denominazione, p.nome_progetto';
$_stm_prj = mysqli_prepare($conn, $query_progetti);
mysqli_stmt_bind_param($_stm_prj, 'i', $tenant_id);
mysqli_stmt_execute($_stm_prj);
$result_progetti = mysqli_stmt_get_result($_stm_prj);
$progetti_list   = mysqli_fetch_all($result_progetti, MYSQLI_ASSOC);

// Filtri (tutti sanitizzati come interi / string)
$mese_filtro    = intval($_GET['mese']    ?? date('m'));
$anno_filtro    = intval($_GET['anno']    ?? date('Y'));
$progetto_filtro = intval($_GET['progetto'] ?? 0);

// Percentuale tasse (da DB o costante)
$tasse_percentuale = TAX_PERCENTAGE;
$_stm_tx = mysqli_prepare($conn, 'SELECT tasse_percentuale FROM tb_anagrafiche WHERE tenant_id = ? LIMIT 1');
mysqli_stmt_bind_param($_stm_tx, 'i', $tenant_id);
mysqli_stmt_execute($_stm_tx);
$res_tasse = mysqli_stmt_get_result($_stm_tx);
if ($row_tasse = mysqli_fetch_assoc($res_tasse)) {
    $tasse_percentuale = floatval($row_tasse['tasse_percentuale']);
}

// --- CALCOLO TOTALI (prepared statements) ---
if ($progetto_filtro > 0) {
    $stmt_tot = mysqli_prepare($conn,
        'SELECT SUM(o.ore) AS tot_ore, SUM(o.ore * p.paga_oraria) AS tot_guadagno
         FROM tb_ore_lavoro o
         JOIN tb_progetti p ON o.progetto_id = p.id_progetto
         WHERE o.tenant_id = ? AND MONTH(o.data_lavoro) = ? AND YEAR(o.data_lavoro) = ? AND o.progetto_id = ?');
    mysqli_stmt_bind_param($stmt_tot, 'iiii', $tenant_id, $mese_filtro, $anno_filtro, $progetto_filtro);
} else {
    $stmt_tot = mysqli_prepare($conn,
        'SELECT SUM(o.ore) AS tot_ore, SUM(o.ore * p.paga_oraria) AS tot_guadagno
         FROM tb_ore_lavoro o
         JOIN tb_progetti p ON o.progetto_id = p.id_progetto
         WHERE o.tenant_id = ? AND MONTH(o.data_lavoro) = ? AND YEAR(o.data_lavoro) = ?');
    mysqli_stmt_bind_param($stmt_tot, 'iii', $tenant_id, $mese_filtro, $anno_filtro);
}
mysqli_stmt_execute($stmt_tot);
$row_totali = mysqli_stmt_get_result($stmt_tot)->fetch_assoc();
mysqli_stmt_close($stmt_tot);

$totale_ore            = floatval($row_totali['tot_ore'] ?? 0);
$totale_guadagno_lordo = floatval($row_totali['tot_guadagno'] ?? 0);
$totale_guadagno_netto = calcolaNetto($totale_guadagno_lordo, $tasse_percentuale);

// --- PAGINAZIONE ---
$records_per_page = 10;
$page   = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $records_per_page;

// Conta totale record
if ($progetto_filtro > 0) {
    $stmt_cnt = mysqli_prepare($conn,
        'SELECT COUNT(*) AS totale FROM tb_ore_lavoro o WHERE o.tenant_id = ? AND MONTH(o.data_lavoro) = ? AND YEAR(o.data_lavoro) = ? AND o.progetto_id = ?');
    mysqli_stmt_bind_param($stmt_cnt, 'iiii', $tenant_id, $mese_filtro, $anno_filtro, $progetto_filtro);
} else {
    $stmt_cnt = mysqli_prepare($conn,
        'SELECT COUNT(*) AS totale FROM tb_ore_lavoro o WHERE o.tenant_id = ? AND MONTH(o.data_lavoro) = ? AND YEAR(o.data_lavoro) = ?');
    mysqli_stmt_bind_param($stmt_cnt, 'iii', $tenant_id, $mese_filtro, $anno_filtro);
}
mysqli_stmt_execute($stmt_cnt);
$total_records = intval(mysqli_stmt_get_result($stmt_cnt)->fetch_assoc()['totale']);
mysqli_stmt_close($stmt_cnt);
$total_pages = max(1, (int)ceil($total_records / $records_per_page));

// Query ore con paginazione
if ($progetto_filtro > 0) {
    $stmt_ore = mysqli_prepare($conn,
        'SELECT o.*, p.nome_progetto, p.paga_oraria, c.denominazione AS cliente_nome
         FROM tb_ore_lavoro o
         JOIN tb_progetti p ON o.progetto_id = p.id_progetto
         JOIN tb_clienti c ON p.id_cliente = c.id_cliente
         WHERE o.tenant_id = ? AND MONTH(o.data_lavoro) = ? AND YEAR(o.data_lavoro) = ? AND o.progetto_id = ?
         ORDER BY o.data_lavoro DESC, o.id_ore DESC
         LIMIT ? OFFSET ?');
    mysqli_stmt_bind_param($stmt_ore, 'iiiiii', $tenant_id, $mese_filtro, $anno_filtro, $progetto_filtro, $records_per_page, $offset);
} else {
    $stmt_ore = mysqli_prepare($conn,
        'SELECT o.*, p.nome_progetto, p.paga_oraria, c.denominazione AS cliente_nome
         FROM tb_ore_lavoro o
         JOIN tb_progetti p ON o.progetto_id = p.id_progetto
         JOIN tb_clienti c ON p.id_cliente = c.id_cliente
         WHERE o.tenant_id = ? AND MONTH(o.data_lavoro) = ? AND YEAR(o.data_lavoro) = ?
         ORDER BY o.data_lavoro DESC, o.id_ore DESC
         LIMIT ? OFFSET ?');
    mysqli_stmt_bind_param($stmt_ore, 'iiiii', $tenant_id, $mese_filtro, $anno_filtro, $records_per_page, $offset);
}
mysqli_stmt_execute($stmt_ore);
$result_ore = mysqli_stmt_get_result($stmt_ore);
$ore_rows   = mysqli_fetch_all($result_ore, MYSQLI_ASSOC);
mysqli_stmt_close($stmt_ore);

mysqli_close($conn);

$mesi_nomi = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];

// Parametri URL base per paginazione/filtri
$base_qs = http_build_query(['mese' => $mese_filtro, 'anno' => $anno_filtro, 'progetto' => $progetto_filtro]);

$page_title  = 'Traccia Ore';
$current_page = 'traccia_ore.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="page-content">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="mb-0"><i class="bi bi-clock-history text-primary me-2"></i>Traccia Ore Lavorate</h2>
        <a href="riepilogo_ore.php?mese=<?= $mese_filtro ?>&anno=<?= $anno_filtro ?>"
           target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-table me-1"></i> Riepilogo Mese
        </a>
    </div>

    <!-- Form inserimento -->
    <div class="card page-card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Registra Ore</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="traccia_ore.php?<?= $base_qs ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="insert">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Data</label>
                        <input type="date" name="data" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Progetto</label>
                        <select name="progetto_id" class="form-select" required>
                            <option value="">Seleziona progetto...</option>
                            <?php foreach ($progetti_list as $prog): ?>
                            <option value="<?= $prog['id_progetto'] ?>">
                                <?= e($prog['cliente_nome']) ?> — <?= e($prog['nome_progetto']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Ore</label>
                        <input type="number" name="ore" class="form-control" step="0.5" min="0.5" max="24" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Note <small class="text-muted">(opz.)</small></label>
                        <input type="text" name="note" class="form-control" maxlength="200"
                               placeholder="Attività svolta...">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i> Registra
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Filtri -->
    <div class="card page-card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Mese</label>
                    <select name="mese" class="form-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $mese_filtro === $m ? 'selected' : '' ?>>
                            <?= $mesi_nomi[$m-1] ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Anno</label>
                    <input type="number" name="anno" class="form-control"
                           value="<?= $anno_filtro ?>" min="2020" max="2035">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Progetto</label>
                    <select name="progetto" class="form-select">
                        <option value="0">Tutti i progetti</option>
                        <?php foreach ($progetti_list as $prog): ?>
                        <option value="<?= $prog['id_progetto'] ?>"
                            <?= $progetto_filtro === intval($prog['id_progetto']) ? 'selected' : '' ?>>
                            <?= e($prog['cliente_nome']) ?> — <?= e($prog['nome_progetto']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-filter me-1"></i> Filtra
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Totali -->
    <div class="row mb-4 g-3">
        <div class="col-md-4">
            <div class="card stat-card bg-primary text-white">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0 fw-bold"><?= number_format($totale_ore, 2, ',', '.') ?> h</h3>
                        <p class="mb-0 opacity-75">Ore Totali Mese</p>
                    </div>
                    <i class="bi bi-clock stat-icon opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card bg-success text-white">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0 fw-bold"><?= formatCurrency($totale_guadagno_lordo) ?></h3>
                        <p class="mb-0 opacity-75">Guadagno Lordo</p>
                    </div>
                    <i class="bi bi-cash-stack stat-icon opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card bg-info text-white">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0 fw-bold"><?= formatCurrency($totale_guadagno_netto) ?></h3>
                        <p class="mb-0 opacity-75">Netto (tasse <?= number_format($tasse_percentuale, 0) ?>%)</p>
                    </div>
                    <i class="bi bi-wallet2 stat-icon opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabella ore -->
    <div class="card page-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="bi bi-table me-2"></i>
                <?= e($mesi_nomi[$mese_filtro-1]) ?> <?= $anno_filtro ?>
            </h5>
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-secondary"><?= $total_records ?> registrazioni</span>
                <a href="export_ore_excel.php?mese=<?= $mese_filtro ?>&anno=<?= $anno_filtro ?>&progetto=<?= $progetto_filtro ?>"
                   class="btn btn-sm btn-outline-success">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> Esporta CSV
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Data</th>
                            <th>Cliente</th>
                            <th>Progetto</th>
                            <th class="text-end">Ore</th>
                            <th class="text-end">Tariffa</th>
                            <th class="text-end">Totale</th>
                            <th>Note</th>
                            <th width="60" class="text-center">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($ore_rows)): ?>
                            <?php foreach ($ore_rows as $row): ?>
                            <tr>
                                <td class="text-nowrap"><?= date('d/m/Y', strtotime($row['data_lavoro'])) ?></td>
                                <td><?= e($row['cliente_nome']) ?></td>
                                <td><?= e($row['nome_progetto']) ?></td>
                                <td class="text-end fw-semibold"><?= number_format($row['ore'], 2, ',', '.') ?> h</td>
                                <td class="text-end"><?= formatCurrency($row['paga_oraria']) ?></td>
                                <td class="text-end fw-semibold text-success"><?= formatCurrency($row['ore'] * $row['paga_oraria']) ?></td>
                                <td><small class="text-muted"><?= e($row['note']) ?></small></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            title="Elimina"
                                            onclick="confirmDelete(<?= intval($row['id_ore']) ?>, '<?= e($mesi_nomi[$mese_filtro-1]) ?> <?= $anno_filtro ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    Nessuna ora registrata per questo periodo
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="card-footer">
            <nav aria-label="Paginazione">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= $base_qs ?>&page=<?= $page - 1 ?>">&laquo;</a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= $base_qs ?>&page=<?= $i ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= $base_qs ?>&page=<?= $page + 1 ?>">&raquo;</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal conferma eliminazione -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h6 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Conferma eliminazione</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-0">
                <p class="mb-0">Eliminare questa registrazione?</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <form method="POST" id="deleteForm" action="traccia_ore.php?<?= $base_qs ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id_ore" id="deleteId" value="">
                    <button type="submit" class="btn btn-sm btn-danger">
                        <i class="bi bi-trash me-1"></i> Elimina
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, label) {
    document.getElementById('deleteId').value = id;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
