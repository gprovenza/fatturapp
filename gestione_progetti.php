<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'includes/tenant.php';

if (!in_array($_SESSION['ruolo'] ?? '', ['admin', 'user'], true)) {
    header('Location: index.php'); exit;
}

$conn      = getDBConnection();
$tenant_id = getTenantId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $nome   = trim($_POST['nome_progetto'] ?? '');
        $cup    = trim($_POST['CUP'] ?? '');
        $paga   = floatval($_POST['paga_oraria'] ?? 0);
        $gruppo = floatval($_POST['tariffa_gruppo'] ?? 0);
        $id_cliente = intval($_POST['id_cliente'] ?? 0);

        $stmt = mysqli_prepare($conn,
            'INSERT INTO tb_progetti (nome_progetto, CUP, paga_oraria, tariffa_gruppo, id_cliente, tenant_id) VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'ssddii', $nome, $cup, $paga, $gruppo, $id_cliente, $tenant_id);
        mysqli_stmt_execute($stmt) ? set_flash('Progetto aggiunto!', 'success') : set_flash('Errore durante l\'inserimento.', 'danger');
        mysqli_stmt_close($stmt);

    } elseif ($action === 'edit') {
        $id     = intval($_POST['id_progetto']);
        $nome   = trim($_POST['nome_progetto'] ?? '');
        $cup    = trim($_POST['CUP'] ?? '');
        $paga   = floatval($_POST['paga_oraria'] ?? 0);
        $gruppo = floatval($_POST['tariffa_gruppo'] ?? 0);
        $id_cliente = intval($_POST['id_cliente'] ?? 0);

        $stmt = mysqli_prepare($conn,
            'UPDATE tb_progetti SET nome_progetto=?, CUP=?, paga_oraria=?, tariffa_gruppo=?, id_cliente=?
             WHERE id_progetto=? AND tenant_id=?');
        mysqli_stmt_bind_param($stmt, 'ssddiii', $nome, $cup, $paga, $gruppo, $id_cliente, $id, $tenant_id);
        mysqli_stmt_execute($stmt) ? set_flash('Progetto modificato!', 'success') : set_flash('Errore modifica.', 'danger');
        mysqli_stmt_close($stmt);

    } elseif ($action === 'delete') {
        $id = intval($_POST['id_progetto']);
        $stmt_chk = mysqli_prepare($conn, 'SELECT COUNT(*) FROM tb_ore_lavoro WHERE progetto_id = ? AND tenant_id = ?');
        mysqli_stmt_bind_param($stmt_chk, 'ii', $id, $tenant_id);
        mysqli_stmt_execute($stmt_chk);
        $n = intval(mysqli_stmt_get_result($stmt_chk)->fetch_row()[0]);
        mysqli_stmt_close($stmt_chk);

        if ($n > 0) {
            set_flash("Impossibile eliminare: $n registrazioni ore collegate a questo progetto.", 'danger');
        } else {
            $stmt = mysqli_prepare($conn, 'DELETE FROM tb_progetti WHERE id_progetto = ? AND tenant_id = ?');
            mysqli_stmt_bind_param($stmt, 'ii', $id, $tenant_id);
            mysqli_stmt_execute($stmt) ? set_flash('Progetto eliminato.', 'success') : set_flash('Errore eliminazione.', 'danger');
            mysqli_stmt_close($stmt);
        }
    }

    header('Location: gestione_progetti.php');
    exit;
}

$_stm_pg = mysqli_prepare($conn,
    'SELECT p.*, c.denominazione AS cliente_nome
     FROM tb_progetti p
     LEFT JOIN tb_clienti c ON p.id_cliente = c.id_cliente
     WHERE p.tenant_id = ?
     ORDER BY c.denominazione, p.nome_progetto');
mysqli_stmt_bind_param($_stm_pg, 'i', $tenant_id);
mysqli_stmt_execute($_stm_pg);
$progetti = mysqli_fetch_all(mysqli_stmt_get_result($_stm_pg), MYSQLI_ASSOC);

$_stm_cl = mysqli_prepare($conn, 'SELECT id_cliente, denominazione FROM tb_clienti WHERE tenant_id = ? ORDER BY denominazione');
mysqli_stmt_bind_param($_stm_cl, 'i', $tenant_id);
mysqli_stmt_execute($_stm_cl);
$clienti = mysqli_fetch_all(mysqli_stmt_get_result($_stm_cl), MYSQLI_ASSOC);
mysqli_close($conn);

$page_title  = 'Gestione Progetti';
$current_page = 'gestione_progetti.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="page-content">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="mb-0"><i class="bi bi-briefcase text-secondary me-2"></i>Gestione Progetti</h2>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAdd">
            <i class="bi bi-plus-circle me-1"></i> Aggiungi Progetto
        </button>
    </div>

    <div class="card page-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Progetto</th>
                            <th>Cliente</th>
                            <th>CUP</th>
                            <th class="text-end">Tariffa Singolo</th>
                            <th class="text-end">Tariffa Gruppo</th>
                            <th width="110" class="text-center">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($progetti)): ?>
                            <?php foreach ($progetti as $row): ?>
                            <tr>
                                <td><strong><?= e($row['nome_progetto']) ?></strong></td>
                                <td><?= e($row['cliente_nome'] ?? '—') ?></td>
                                <td><small class="text-muted"><?= e($row['CUP']) ?></small></td>
                                <td class="text-end"><?= formatCurrency($row['paga_oraria']) ?></td>
                                <td class="text-end"><?= floatval($row['tariffa_gruppo']) > 0 ? formatCurrency($row['tariffa_gruppo']) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-warning"
                                            onclick='editProgetto(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                            title="Modifica">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="confirmDelete(<?= $row['id_progetto'] ?>, '<?= e($row['nome_progetto']) ?>')"
                                            title="Elimina">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">Nessun progetto registrato</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
// Opzioni cliente condivise
$clienti_options = '<option value="">Nessun cliente</option>';
foreach ($clienti as $c) {
    $clienti_options .= '<option value="' . $c['id_cliente'] . '">' . e($c['denominazione']) . '</option>';
}
?>

<!-- Modal Aggiungi -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header"><h5 class="modal-title">Aggiungi Progetto</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Cliente</label>
                        <select name="id_cliente" class="form-select"><?= $clienti_options ?></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nome Progetto <span class="text-danger">*</span></label>
                        <input type="text" name="nome_progetto" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">CUP</label>
                        <input type="text" name="CUP" class="form-control">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tariffa Singolo (€) <span class="text-danger">*</span></label>
                            <input type="number" name="paga_oraria" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tariffa Gruppo (€)</label>
                            <input type="number" name="tariffa_gruppo" class="form-control" step="0.01" min="0" value="0">
                            <small class="text-muted">Lascia 0 se non prevista</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i>Salva</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Modifica -->
<div class="modal fade" id="modalEdit" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="formEdit">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id_progetto" id="edit_id">
                <div class="modal-header"><h5 class="modal-title">Modifica Progetto</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Cliente</label>
                        <select name="id_cliente" id="edit_cliente" class="form-select"><?= $clienti_options ?></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nome Progetto <span class="text-danger">*</span></label>
                        <input type="text" name="nome_progetto" id="edit_nome" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">CUP</label>
                        <input type="text" name="CUP" id="edit_cup" class="form-control">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tariffa Singolo (€) <span class="text-danger">*</span></label>
                            <input type="number" name="paga_oraria" id="edit_paga" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tariffa Gruppo (€)</label>
                            <input type="number" name="tariffa_gruppo" id="edit_gruppo" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-save me-1"></i>Aggiorna</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Elimina -->
<div class="modal fade" id="modalDelete" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0"><h6 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Elimina progetto</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body py-1"><p class="mb-0">Eliminare <strong id="deleteNome"></strong>?</p></div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id_progetto" id="deleteId">
                    <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function editProgetto(data) {
    document.getElementById('edit_id').value    = data.id_progetto;
    document.getElementById('edit_nome').value  = data.nome_progetto;
    document.getElementById('edit_cup').value   = data.CUP || '';
    document.getElementById('edit_paga').value  = data.paga_oraria;
    document.getElementById('edit_gruppo').value = data.tariffa_gruppo || 0;
    const sel = document.getElementById('edit_cliente');
    if (sel) sel.value = data.id_cliente || '';
    new bootstrap.Modal(document.getElementById('modalEdit')).show();
}
function confirmDelete(id, nome) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteNome').textContent = nome;
    new bootstrap.Modal(document.getElementById('modalDelete')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
