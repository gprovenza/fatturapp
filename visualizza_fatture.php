<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'includes/tenant.php';

$conn      = getDBConnection();
$tenant_id = getTenantId();

// Eliminazione fattura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();

    $id_fattura = intval($_POST['id_fattura'] ?? 0);

    // Recupera info fattura (scoped al tenant)
    $stmt = mysqli_prepare($conn, 'SELECT numero_fattura, pdf_path FROM tb_fatture WHERE id_fattura = ? AND tenant_id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $id_fattura, $tenant_id);
    mysqli_stmt_execute($stmt);
    $fattura_da_eliminare = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if ($fattura_da_eliminare) {
        // Controlla fattura elettronica collegata
        $stmt_fe = mysqli_prepare($conn, 'SELECT id_fattura_elettronica FROM tb_fatture_elettroniche WHERE numero_proforma = ?');
        mysqli_stmt_bind_param($stmt_fe, 'i', $id_fattura);
        mysqli_stmt_execute($stmt_fe);
        $fe_linked = mysqli_stmt_get_result($stmt_fe)->fetch_assoc();
        mysqli_stmt_close($stmt_fe);

        if ($fe_linked) {
            set_flash('Impossibile eliminare: fattura elettronica collegata!', 'danger');
        } else {
            mysqli_begin_transaction($conn);
            try {
                $stmt_del = mysqli_prepare($conn, 'DELETE FROM tb_fatture WHERE id_fattura = ? AND tenant_id = ?');
                mysqli_stmt_bind_param($stmt_del, 'ii', $id_fattura, $tenant_id);
                mysqli_stmt_execute($stmt_del);
                mysqli_stmt_close($stmt_del);

                if (!empty($fattura_da_eliminare['pdf_path']) && file_exists($fattura_da_eliminare['pdf_path'])) {
                    unlink($fattura_da_eliminare['pdf_path']);
                }
                mysqli_commit($conn);
                set_flash('Fattura eliminata con successo!', 'success');
            } catch (Exception $e) {
                mysqli_rollback($conn);
                error_log('Errore eliminazione fattura: ' . $e->getMessage());
                set_flash('Errore durante l\'eliminazione. Riprova.', 'danger');
            }
        }
    }

    header('Location: visualizza_fatture.php');
    exit;
}

// Ricerca e paginazione
$search = trim($_GET['search'] ?? '');
$page   = max(1, intval($_GET['page'] ?? 1));
$records_per_page = 10;

// COUNT totale (stessa WHERE, senza dati extra)
if ($search !== '') {
    $like     = '%' . $search . '%';
    $stmt_cnt = mysqli_prepare($conn,
        'SELECT COUNT(*)
         FROM tb_fatture f
         JOIN tb_clienti c ON f.cliente_id = c.id_cliente
         WHERE f.tenant_id = ? AND (f.numero_fattura LIKE ? OR c.denominazione LIKE ? OR f.mese LIKE ? OR CAST(f.anno AS CHAR) LIKE ?)');
    mysqli_stmt_bind_param($stmt_cnt, 'issss', $tenant_id, $like, $like, $like, $like);
} else {
    $stmt_cnt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM tb_fatture WHERE tenant_id = ?');
    mysqli_stmt_bind_param($stmt_cnt, 'i', $tenant_id);
}
mysqli_stmt_execute($stmt_cnt);
$total_records = (int) mysqli_stmt_get_result($stmt_cnt)->fetch_row()[0];
mysqli_stmt_close($stmt_cnt);
$total_pages = max(1, (int)ceil($total_records / $records_per_page));
$page   = min($page, $total_pages);
$offset = ($page - 1) * $records_per_page;

// Query principale paginata
if ($search !== '') {
    $like   = '%' . $search . '%';
    $stmt_q = mysqli_prepare($conn,
        'SELECT f.*, c.denominazione AS cliente_nome,
                fe.numero_fattura AS fattura_elettronica,
                fe.id_fattura_elettronica,
                fe.pdf_filename, fe.pdf_path AS fe_pdf_path,
                fe.xml_filename, fe.xml_path AS fe_xml_path,
                a.tasse_percentuale
         FROM tb_fatture f
         JOIN tb_clienti c ON f.cliente_id = c.id_cliente
         LEFT JOIN tb_fatture_elettroniche fe ON fe.numero_proforma = f.id_fattura
         LEFT JOIN tb_anagrafiche a ON f.anagrafica_id = a.id_anagrafica
         WHERE f.tenant_id = ? AND (f.numero_fattura LIKE ? OR c.denominazione LIKE ? OR f.mese LIKE ? OR CAST(f.anno AS CHAR) LIKE ?)
         ORDER BY f.data_creazione DESC
         LIMIT ? OFFSET ?');
    mysqli_stmt_bind_param($stmt_q, 'issssii', $tenant_id, $like, $like, $like, $like, $records_per_page, $offset);
} else {
    $stmt_q = mysqli_prepare($conn,
        'SELECT f.*, c.denominazione AS cliente_nome,
                fe.numero_fattura AS fattura_elettronica,
                fe.id_fattura_elettronica,
                fe.pdf_filename, fe.pdf_path AS fe_pdf_path,
                fe.xml_filename, fe.xml_path AS fe_xml_path,
                a.tasse_percentuale
         FROM tb_fatture f
         JOIN tb_clienti c ON f.cliente_id = c.id_cliente
         LEFT JOIN tb_fatture_elettroniche fe ON fe.numero_proforma = f.id_fattura
         LEFT JOIN tb_anagrafiche a ON f.anagrafica_id = a.id_anagrafica
         WHERE f.tenant_id = ?
         ORDER BY f.data_creazione DESC
         LIMIT ? OFFSET ?');
    mysqli_stmt_bind_param($stmt_q, 'iii', $tenant_id, $records_per_page, $offset);
}
mysqli_stmt_execute($stmt_q);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt_q), MYSQLI_ASSOC);
mysqli_stmt_close($stmt_q);
mysqli_close($conn);

$page_title  = 'Archivio Fatture';
$current_page = 'visualizza_fatture.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="page-content">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <h2 class="mb-0"><i class="bi bi-folder2-open text-info me-2"></i>Archivio Fatture</h2>
        <span class="badge bg-secondary fs-6"><?= $total_records ?> fatture</span>
    </div>

    <!-- Ricerca -->
    <form method="GET" class="mb-4">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" name="search" class="form-control"
                   placeholder="Cerca per numero, cliente, mese, anno..."
                   value="<?= e($search) ?>">
            <button class="btn btn-primary" type="submit">Cerca</button>
            <?php if ($search !== ''): ?>
            <a href="visualizza_fatture.php" class="btn btn-outline-secondary">
                <i class="bi bi-x"></i> Reset
            </a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Download multipli -->
    <form action="download_multipli.php" method="POST" id="form-multipli">
        <?= csrf_field() ?>
        <div class="mb-3 d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAll()">
                <i class="bi bi-check-all me-1"></i> Seleziona pagina
            </button>
            <button type="submit" class="btn btn-sm btn-success" id="btn-download-multi" disabled>
                <i class="bi bi-download me-1"></i> Scarica selezionati (ZIP)
            </button>
        </div>

        <div class="card page-card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th width="45"><input type="checkbox" id="select-all" class="form-check-input"></th>
                                <th>Numero</th>
                                <th>Cliente</th>
                                <th>Periodo</th>
                                <th class="text-center">Elettronica</th>
                                <th class="text-end">Totale</th>
                                <th class="text-center">Pagata</th>
                                <th>Creata</th>
                                <th width="120" class="text-center">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rows)): ?>
                                <?php foreach ($rows as $row):
                                    $tasse_pct    = floatval($row['tasse_percentuale'] ?? TAX_PERCENTAGE);
                                    $tasse_imp    = floatval($row['totale_fattura']) * ($tasse_pct / 100);
                                    $netto        = floatval($row['totale_fattura']) - $tasse_imp;

                                    $has_fe_pdf  = !empty($row['fe_pdf_path']) && file_exists($row['fe_pdf_path']);
                                    $has_fe_xml  = !empty($row['fe_xml_path']) && file_exists($row['fe_xml_path']);
                                    $has_proforma = !empty($row['pdf_path']) && file_exists($row['pdf_path']);
                                    $has_any     = $has_fe_pdf || $has_proforma;
                                ?>
                                <tr>
                                    <td><input type="checkbox" name="fatture[]" value="<?= $row['id_fattura'] ?>" class="form-check-input fattura-check"></td>
                                    <td><strong><?= e($row['numero_fattura']) ?></strong></td>
                                    <td><?= e($row['cliente_nome']) ?></td>
                                    <td class="text-nowrap"><?= e($row['mese']) ?> <?= intval($row['anno']) ?></td>
                                    <td class="text-center">
                                        <?php if (!empty($row['fattura_elettronica'])): ?>
                                        <a href="upload_fattura.php?highlight=<?= $row['id_fattura_elettronica'] ?>#archivio"
                                           class="badge bg-success text-decoration-none">
                                            <i class="bi bi-check-circle"></i> OK
                                        </a>
                                        <?php else: ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="bi bi-clock"></i> Attesa
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <span data-bs-toggle="tooltip" data-bs-html="true"
                                              title="Tasse (<?= number_format($tasse_pct, 0) ?>%): <?= formatCurrency($tasse_imp) ?><br><strong>Netto: <?= formatCurrency($netto) ?></strong>"
                                              style="cursor:help; border-bottom:1px dotted;">
                                            <?= formatCurrency($row['totale_fattura']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="form-check form-switch d-flex justify-content-center align-items-center gap-1 mb-0">
                                            <input class="form-check-input toggle-pagamento" type="checkbox"
                                                   data-id="<?= $row['id_fattura'] ?>"
                                                   data-csrf="<?= e(csrf_token()) ?>"
                                                   <?= $row['pagata'] ? 'checked' : '' ?>>
                                            <small class="text-muted pagamento-label">
                                                <?= ($row['pagata'] && $row['data_pagamento']) ? formatDate($row['data_pagamento']) : '' ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td class="text-nowrap"><small><?= date('d/m/Y', strtotime($row['data_creazione'])) ?></small></td>
                                    <td class="text-center">
                                        <?php if ($has_any): ?>
                                        <a href="download_pdf.php?id=<?= $row['id_fattura'] ?>" class="btn btn-sm btn-outline-success" target="_blank"
                                           title="<?= $has_fe_pdf && $has_fe_xml ? 'ZIP PDF+XML' : ($has_fe_pdf ? 'PDF Elettronico' : 'PDF Pro-forma') ?>">
                                            <i class="bi bi-<?= ($has_fe_pdf && $has_fe_xml) ? 'file-earmark-zip' : 'file-pdf' ?>"></i>
                                        </a>
                                        <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary" disabled title="Nessun file"><i class="bi bi-x"></i></button>
                                        <?php endif; ?>

                                        <?php if (empty($row['fattura_elettronica'])): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                title="Elimina"
                                                onclick="confirmDeleteFattura(<?= $row['id_fattura'] ?>, '<?= e($row['numero_fattura']) ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary" disabled title="Fattura con elettronica: non eliminabile">
                                            <i class="bi bi-lock"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                        <?= $search ? 'Nessuna fattura trovata per "' . e($search) . '"' : 'Nessuna fattura presente' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($total_pages > 1): ?>
            <div class="card-footer py-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <small class="text-muted">
                        Pagina <?= $page ?> di <?= $total_pages ?>
                        &nbsp;·&nbsp; <?= $total_records ?> fatture totali
                    </small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $qs_base   = $search ? ['search' => $search] : [];
                            $prev_page = max(1, $page - 1);
                            $next_page = min($total_pages, $page + 1);
                            ?>
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($qs_base, ['page' => $prev_page])) ?>">&laquo;</a>
                            </li>
                            <?php
                            $last_shown = 0;
                            $pages_to_show = [];
                            for ($i = 1; $i <= $total_pages; $i++) {
                                if ($i === 1 || $i === $total_pages || abs($i - $page) <= 2) {
                                    $pages_to_show[] = $i;
                                }
                            }
                            foreach ($pages_to_show as $i):
                                if ($last_shown > 0 && $i - $last_shown > 1): ?>
                                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                <?php endif; ?>
                                <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($qs_base, ['page' => $i])) ?>"><?= $i ?></a>
                                </li>
                                <?php $last_shown = $i; endforeach; ?>
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($qs_base, ['page' => $next_page])) ?>">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Modal conferma eliminazione -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h6 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Elimina fattura</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-1">
                <p class="mb-0">Eliminare la fattura <strong id="deleteNumero"></strong>?<br>
                <small class="text-muted">Questa operazione non può essere annullata.</small></p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <form method="POST" id="deleteForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id_fattura" id="deleteId">
                    <button type="submit" class="btn btn-sm btn-danger">
                        <i class="bi bi-trash me-1"></i> Elimina
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Modal eliminazione
function confirmDeleteFattura(id, numero) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteNumero').textContent = numero;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Checkbox select all
function selectAll() {
    const all = document.getElementById('select-all');
    all.checked = !all.checked;
    document.querySelectorAll('.fattura-check').forEach(cb => cb.checked = all.checked);
    updateDownloadBtn();
}
document.getElementById('select-all').addEventListener('change', function() {
    document.querySelectorAll('.fattura-check').forEach(cb => cb.checked = this.checked);
    updateDownloadBtn();
});
document.querySelectorAll('.fattura-check').forEach(cb => {
    cb.addEventListener('change', updateDownloadBtn);
});
function updateDownloadBtn() {
    const selected = document.querySelectorAll('.fattura-check:checked').length;
    document.getElementById('btn-download-multi').disabled = selected === 0;
}

// Toggle pagamento via AJAX con CSRF
document.querySelectorAll('.toggle-pagamento').forEach(toggle => {
    toggle.addEventListener('change', function() {
        const idFattura = this.dataset.id;
        const pagata    = this.checked ? 1 : 0;
        const csrf      = this.dataset.csrf;
        const label     = this.closest('div').querySelector('.pagamento-label');
        const self      = this;

        fetch('aggiorna_pagamento.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({id_fattura: idFattura, pagata, csrf_token: csrf})
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                label.textContent = pagata ? (d.data_pagamento ? new Date(d.data_pagamento).toLocaleDateString('it-IT', {day:'2-digit',month:'2-digit',year:'numeric'}) : '') : '';
                showToast(pagata ? 'Pagamento registrato' : 'Pagamento rimosso', 'success');
            } else {
                self.checked = !self.checked;
                showToast('Errore aggiornamento pagamento', 'danger');
            }
        })
        .catch(() => {
            self.checked = !self.checked;
            showToast('Errore di rete', 'danger');
        });
    });
});

// Inizializza tooltip Bootstrap
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
});
</script>

<?php require_once 'includes/footer.php'; ?>
