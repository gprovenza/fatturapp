<?php
require_once 'auth_admin.php';
require_once 'db.php';

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    switch ($action) {

        case 'add':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $email    = trim($_POST['email'] ?? '');
            $ruolo    = in_array($_POST['ruolo'] ?? '', ['admin', 'user', 'commercialista'], true)
                        ? $_POST['ruolo'] : 'user';

            if (strlen($password) < 6) {
                set_flash('La password deve essere di almeno 6 caratteri.', 'danger');
                break;
            }

            $stmt_chk = mysqli_prepare($conn, 'SELECT id_utente FROM tb_utenti WHERE username = ?');
            mysqli_stmt_bind_param($stmt_chk, 's', $username);
            mysqli_stmt_execute($stmt_chk);
            $dup = mysqli_stmt_get_result($stmt_chk)->fetch_assoc();
            mysqli_stmt_close($stmt_chk);

            if ($dup) {
                set_flash('Username già esistente!', 'danger');
                break;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, 'INSERT INTO tb_utenti (username, password_hash, ruolo, email) VALUES (?, ?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'ssss', $username, $hash, $ruolo, $email);
            mysqli_stmt_execute($stmt)
                ? set_flash('Utente creato con successo!', 'success')
                : set_flash('Errore: ' . mysqli_error($conn), 'danger');
            mysqli_stmt_close($stmt);
            break;

        case 'edit':
            $id       = intval($_POST['id_utente']);
            $username = trim($_POST['username'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $ruolo    = in_array($_POST['ruolo'] ?? '', ['admin', 'user', 'commercialista'], true)
                        ? $_POST['ruolo'] : 'user';

            // Impedisce di togliere l'ultimo admin
            if ($ruolo !== 'admin') {
                $stmt_cnt = mysqli_prepare($conn,
                    "SELECT COUNT(*) FROM tb_utenti WHERE ruolo = 'admin' AND id_utente != ?");
                mysqli_stmt_bind_param($stmt_cnt, 'i', $id);
                mysqli_stmt_execute($stmt_cnt);
                $n = intval(mysqli_stmt_get_result($stmt_cnt)->fetch_row()[0]);
                mysqli_stmt_close($stmt_cnt);
                if ($n < 1) {
                    set_flash("Non puoi rimuovere l'ultimo amministratore!", 'danger');
                    break;
                }
            }

            $stmt = mysqli_prepare($conn, 'UPDATE tb_utenti SET username=?, email=?, ruolo=? WHERE id_utente=?');
            mysqli_stmt_bind_param($stmt, 'sssi', $username, $email, $ruolo, $id);
            mysqli_stmt_execute($stmt)
                ? set_flash('Utente modificato con successo!', 'success')
                : set_flash('Errore modifica: ' . mysqli_error($conn), 'danger');
            mysqli_stmt_close($stmt);
            break;

        case 'reset_password':
            $id  = intval($_POST['id_utente']);
            $pwd = $_POST['nuova_password'] ?? '';
            if (strlen($pwd) < 6) {
                set_flash('La password deve essere di almeno 6 caratteri.', 'danger');
                break;
            }
            $hash = password_hash($pwd, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, 'UPDATE tb_utenti SET password_hash=? WHERE id_utente=?');
            mysqli_stmt_bind_param($stmt, 'si', $hash, $id);
            mysqli_stmt_execute($stmt)
                ? set_flash('Password resettata con successo!', 'success')
                : set_flash('Errore reset password: ' . mysqli_error($conn), 'danger');
            mysqli_stmt_close($stmt);
            break;

        case 'delete':
            $id = intval($_POST['id_utente']);
            if ($id === (int)$_SESSION['user_id']) {
                set_flash('Non puoi eliminare il tuo stesso account!', 'danger');
                break;
            }
            $stmt_info = mysqli_prepare($conn, 'SELECT ruolo FROM tb_utenti WHERE id_utente=?');
            mysqli_stmt_bind_param($stmt_info, 'i', $id);
            mysqli_stmt_execute($stmt_info);
            $user_info = mysqli_stmt_get_result($stmt_info)->fetch_assoc();
            mysqli_stmt_close($stmt_info);

            if ($user_info && $user_info['ruolo'] === 'admin') {
                $stmt_cnt = mysqli_prepare($conn, "SELECT COUNT(*) FROM tb_utenti WHERE ruolo = 'admin'");
                mysqli_stmt_execute($stmt_cnt);
                $n = intval(mysqli_stmt_get_result($stmt_cnt)->fetch_row()[0]);
                mysqli_stmt_close($stmt_cnt);
                if ($n <= 1) {
                    set_flash("Non puoi eliminare l'ultimo amministratore!", 'danger');
                    break;
                }
            }

            $stmt = mysqli_prepare($conn, 'DELETE FROM tb_utenti WHERE id_utente=?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt)
                ? set_flash('Utente eliminato con successo!', 'success')
                : set_flash('Errore eliminazione: ' . mysqli_error($conn), 'danger');
            mysqli_stmt_close($stmt);
            break;
    }

    header('Location: gestione_utenti.php');
    exit;
}

$utenti = mysqli_fetch_all(
    mysqli_query($conn, "SELECT * FROM tb_utenti ORDER BY ruolo DESC, username"),
    MYSQLI_ASSOC
);
mysqli_close($conn);

$page_title   = 'Gestione Utenti';
$current_page = 'gestione_utenti.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="page-content">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="mb-0">
            <i class="bi bi-person-badge text-danger me-2"></i>Gestione Utenti
            <span class="badge bg-danger ms-2 fs-6">Solo Admin</span>
        </h2>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAdd">
            <i class="bi bi-person-plus me-1"></i> Aggiungi Utente
        </button>
    </div>

    <div class="card page-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Ruolo</th>
                            <th>Data Creazione</th>
                            <th width="160" class="text-center">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($utenti)): ?>
                            <?php foreach ($utenti as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= e($row['username']) ?></strong>
                                    <?php if ($row['id_utente'] == $_SESSION['user_id']): ?>
                                        <span class="badge bg-info ms-1" style="font-size:.65rem;">Tu</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($row['email'] ?? '') ?></td>
                                <td>
                                    <?php
                                    $badge_color = match($row['ruolo']) {
                                        'admin'          => 'danger',
                                        'commercialista' => 'info',
                                        default          => 'secondary',
                                    };
                                    ?>
                                    <span class="badge bg-<?= $badge_color ?>">
                                        <?= e(ucfirst($row['ruolo'])) ?>
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    <small><?= date('d/m/Y H:i', strtotime($row['data_creazione'])) ?></small>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-warning"
                                            onclick='editUtente(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                            title="Modifica">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-info"
                                            onclick="resetPassword(<?= $row['id_utente'] ?>, '<?= e($row['username']) ?>')"
                                            title="Reset Password">
                                        <i class="bi bi-key"></i>
                                    </button>
                                    <?php if ($row['id_utente'] != $_SESSION['user_id']): ?>
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="confirmDelete(<?= $row['id_utente'] ?>, '<?= e($row['username']) ?>')"
                                            title="Elimina">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled title="Non puoi eliminare te stesso">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">
                                    <i class="bi bi-people fs-3 d-block mb-2"></i>Nessun utente trovato
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Aggiungi -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2 text-success"></i>Aggiungi Utente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                        <div class="form-text">Minimo 6 caratteri.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ruolo <span class="text-danger">*</span></label>
                        <select name="ruolo" class="form-select" required>
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                            <option value="commercialista">Commercialista</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i>Crea Utente</button>
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
                <input type="hidden" name="id_utente" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2 text-warning"></i>Modifica Utente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" id="edit_username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ruolo <span class="text-danger">*</span></label>
                        <select name="ruolo" id="edit_ruolo" class="form-select" required>
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                            <option value="commercialista">Commercialista</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-save me-1"></i>Salva Modifiche</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Reset Password -->
<div class="modal fade" id="modalResetPw" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id_utente" id="reset_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-key me-2 text-info"></i>Reset Password —
                        <span id="reset_username" class="text-muted"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nuova Password <span class="text-danger">*</span></label>
                        <input type="password" name="nuova_password" class="form-control" required minlength="6">
                        <div class="form-text">Minimo 6 caratteri.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-info text-white"><i class="bi bi-key me-1"></i>Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Elimina -->
<div class="modal fade" id="modalDelete" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h6 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Elimina utente</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-1">
                <p class="mb-0">Eliminare <strong id="deleteNome"></strong>?</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id_utente" id="deleteId">
                    <button type="submit" class="btn btn-sm btn-danger">
                        <i class="bi bi-trash me-1"></i>Elimina
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function editUtente(data) {
    document.getElementById('edit_id').value       = data.id_utente;
    document.getElementById('edit_username').value = data.username;
    document.getElementById('edit_email').value    = data.email || '';
    document.getElementById('edit_ruolo').value    = data.ruolo;
    new bootstrap.Modal(document.getElementById('modalEdit')).show();
}
function resetPassword(id, username) {
    document.getElementById('reset_id').value            = id;
    document.getElementById('reset_username').textContent = username;
    new bootstrap.Modal(document.getElementById('modalResetPw')).show();
}
function confirmDelete(id, nome) {
    document.getElementById('deleteId').value            = id;
    document.getElementById('deleteNome').textContent     = nome;
    new bootstrap.Modal(document.getElementById('modalDelete')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
