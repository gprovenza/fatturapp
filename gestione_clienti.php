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
        if (!canCreate('cliente', $conn)) {
            set_flash('Hai raggiunto il limite di clienti del piano Free. <a href="saas/billing.php">Passa a Pro →</a>', 'warning');
            header('Location: gestione_clienti.php'); exit;
        }
        $stmt = mysqli_prepare($conn,
            'INSERT INTO tb_clienti (denominazione, indirizzo, cap, citta, provincia, partita_iva, codice_fiscale, SDI, tenant_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'ssssssssi',
            $_POST['denominazione'], $_POST['indirizzo'], $_POST['cap'],
            $_POST['citta'], $_POST['provincia'], $_POST['partita_iva'],
            $_POST['codice_fiscale'], $_POST['SDI'], $tenant_id);
        mysqli_stmt_execute($stmt) ? set_flash('Cliente aggiunto con successo!', 'success') : set_flash('Errore durante il salvataggio.', 'danger');
        mysqli_stmt_close($stmt);

    } elseif ($action === 'edit') {
        $stmt = mysqli_prepare($conn,
            'UPDATE tb_clienti SET denominazione=?, indirizzo=?, cap=?, citta=?, provincia=?, partita_iva=?, codice_fiscale=?, SDI=?
             WHERE id_cliente=? AND tenant_id=?');
        mysqli_stmt_bind_param($stmt, 'ssssssssii',
            $_POST['denominazione'], $_POST['indirizzo'], $_POST['cap'],
            $_POST['citta'], $_POST['provincia'], $_POST['partita_iva'],
            $_POST['codice_fiscale'], $_POST['SDI'], intval($_POST['id_cliente']), $tenant_id);
        mysqli_stmt_execute($stmt) ? set_flash('Cliente modificato con successo!', 'success') : set_flash('Errore durante la modifica.', 'danger');
        mysqli_stmt_close($stmt);

    } elseif ($action === 'delete') {
        $id = intval($_POST['id_cliente']);
        $stmt_chk = mysqli_prepare($conn, 'SELECT COUNT(*) FROM tb_fatture WHERE cliente_id = ? AND tenant_id = ?');
        mysqli_stmt_bind_param($stmt_chk, 'ii', $id, $tenant_id);
        mysqli_stmt_execute($stmt_chk);
        $n = intval(mysqli_stmt_get_result($stmt_chk)->fetch_row()[0]);
        mysqli_stmt_close($stmt_chk);

        if ($n > 0) {
            set_flash("Impossibile eliminare: $n fatture collegate a questo cliente.", 'danger');
        } else {
            $stmt = mysqli_prepare($conn, 'DELETE FROM tb_clienti WHERE id_cliente = ? AND tenant_id = ?');
            mysqli_stmt_bind_param($stmt, 'ii', $id, $tenant_id);
            mysqli_stmt_execute($stmt) ? set_flash('Cliente eliminato.', 'success') : set_flash('Errore eliminazione.', 'danger');
            mysqli_stmt_close($stmt);
        }
    }

    header('Location: gestione_clienti.php');
    exit;
}

$_stm_cl = mysqli_prepare($conn, 'SELECT * FROM tb_clienti WHERE tenant_id = ? ORDER BY denominazione');
mysqli_stmt_bind_param($_stm_cl, 'i', $tenant_id);
mysqli_stmt_execute($_stm_cl);
$clienti = mysqli_fetch_all(mysqli_stmt_get_result($_stm_cl), MYSQLI_ASSOC);
mysqli_close($conn);

$page_title  = 'Gestione Clienti';
$current_page = 'gestione_clienti.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="page-content">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="mb-0"><i class="bi bi-people text-primary me-2"></i>Gestione Clienti</h2>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAdd">
            <i class="bi bi-plus-circle me-1"></i> Aggiungi Cliente
        </button>
    </div>

    <div class="card page-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Denominazione</th>
                            <th>Indirizzo</th>
                            <th>P.IVA</th>
                            <th>SDI</th>
                            <th width="110" class="text-center">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($clienti)): ?>
                            <?php foreach ($clienti as $row): ?>
                            <tr>
                                <td><strong><?= e($row['denominazione']) ?></strong></td>
                                <td class="text-muted small"><?= e($row['indirizzo']) ?>, <?= e($row['cap']) ?> <?= e($row['citta']) ?> (<?= e($row['provincia']) ?>)</td>
                                <td><?= e($row['partita_iva']) ?></td>
                                <td><code><?= e($row['SDI']) ?></code></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-warning"
                                            onclick='editCliente(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                            title="Modifica">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="confirmDelete(<?= $row['id_cliente'] ?>, '<?= e($row['denominazione']) ?>')"
                                            title="Elimina">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">
                                <i class="bi bi-people fs-3 d-block mb-2"></i>Nessun cliente registrato
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Campi form riusabili -->
<?php
function clienteFormFields(string $prefix = ''): void {
    $fields = [
        ['name' => 'denominazione', 'label' => 'Denominazione', 'col' => '12', 'req' => true],
        ['name' => 'indirizzo',     'label' => 'Indirizzo',     'col' => '8',  'req' => false],
        ['name' => 'cap',           'label' => 'CAP',           'col' => '4',  'req' => false, 'max' => 5],
        ['name' => 'citta',         'label' => 'Città',         'col' => '6',  'req' => false],
        ['name' => 'provincia',     'label' => 'Prov.',         'col' => '6',  'req' => false, 'max' => 2],
        ['name' => 'partita_iva',   'label' => 'Partita IVA',   'col' => '6',  'req' => true],
        ['name' => 'codice_fiscale','label' => 'Cod. Fiscale',  'col' => '6',  'req' => false],
        ['name' => 'SDI',           'label' => 'Codice SDI',    'col' => '12', 'req' => true],
    ];
    echo '<div class="row g-2">';
    foreach ($fields as $f) {
        $id  = $prefix . $f['name'];
        $req = $f['req'] ? 'required' : '';
        $max = isset($f['max']) ? 'maxlength="' . $f['max'] . '"' : '';
        echo '<div class="col-md-' . $f['col'] . '">';
        echo '<label class="form-label fw-semibold" for="' . $id . '">' . $f['label'] . ($f['req'] ? ' <span class="text-danger">*</span>' : '') . '</label>';
        echo '<input type="text" name="' . $f['name'] . '" id="' . $id . '" class="form-control" ' . $req . ' ' . $max . '>';
        echo '</div>';
    }
    echo '</div>';
}
?>

<!-- Modal Aggiungi -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-plus-circle me-2 text-success"></i>Aggiungi Cliente</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><?php clienteFormFields('add_'); ?></div>
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
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="formEdit">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id_cliente" id="edit_id">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil me-2 text-warning"></i>Modifica Cliente</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><?php clienteFormFields('edit_'); ?></div>
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
            <div class="modal-header border-0"><h6 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Elimina cliente</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body py-1"><p class="mb-0">Eliminare <strong id="deleteNome"></strong>?</p></div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id_cliente" id="deleteId">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i>Elimina</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function editCliente(data) {
    const f = document.getElementById('formEdit');
    document.getElementById('edit_id').value = data.id_cliente;
    ['denominazione','indirizzo','cap','citta','provincia','partita_iva','codice_fiscale','SDI'].forEach(k => {
        const el = f.querySelector('[name="' + k + '"]');
        if (el) el.value = data[k] || '';
    });
    new bootstrap.Modal(document.getElementById('modalEdit')).show();
}
function confirmDelete(id, nome) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteNome').textContent = nome;
    new bootstrap.Modal(document.getElementById('modalDelete')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
