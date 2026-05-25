<?php
/**
 * Lista e gestione tenant per l'admin SaaS.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../db.php';

$conn = getDBConnection();

// Azioni POST: cambio piano, sospensione, riattivazione
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action    = $_POST['action'] ?? '';
    $target_id = intval($_POST['tenant_id'] ?? 0);

    if ($target_id > 0) {
        switch ($action) {
            case 'set_pro':
                $stm = mysqli_prepare($conn,
                    "SELECT id FROM saas_plans WHERE LOWER(name)='pro' LIMIT 1");
                mysqli_stmt_execute($stm);
                $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stm));
                if ($r) {
                    $pro_id = (int)$r['id'];
                    $end    = date('Y-m-d H:i:s', strtotime('+1 month'));
                    $now    = date('Y-m-d H:i:s');
                    $stm2   = mysqli_prepare($conn,
                        "UPDATE saas_subscriptions
                         SET plan_id=?, status='active', current_period_start=?, current_period_end=?, trial_ends_at=NULL, cancelled_at=NULL
                         WHERE tenant_id=?");
                    mysqli_stmt_bind_param($stm2, 'issi', $pro_id, $now, $end, $target_id);
                    mysqli_stmt_execute($stm2);
                    set_flash("Tenant #$target_id promosso a Pro per 1 mese.", 'success');
                }
                break;

            case 'set_free':
                $stm = mysqli_prepare($conn,
                    "SELECT id FROM saas_plans WHERE LOWER(name)='free' LIMIT 1");
                mysqli_stmt_execute($stm);
                $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stm));
                if ($r) {
                    $free_id = (int)$r['id'];
                    $stm2    = mysqli_prepare($conn,
                        "UPDATE saas_subscriptions SET plan_id=?, status='expired', cancelled_at=NOW() WHERE tenant_id=?");
                    mysqli_stmt_bind_param($stm2, 'ii', $free_id, $target_id);
                    mysqli_stmt_execute($stm2);
                    set_flash("Tenant #$target_id degradato a Free.", 'warning');
                }
                break;

            case 'suspend':
                $stm = mysqli_prepare($conn,
                    "UPDATE saas_tenants SET status='suspended' WHERE id=?");
                mysqli_stmt_bind_param($stm, 'i', $target_id);
                mysqli_stmt_execute($stm);
                set_flash("Tenant #$target_id sospeso.", 'warning');
                break;

            case 'reactivate':
                $stm = mysqli_prepare($conn,
                    "UPDATE saas_tenants SET status='active' WHERE id=?");
                mysqli_stmt_bind_param($stm, 'i', $target_id);
                mysqli_stmt_execute($stm);
                set_flash("Tenant #$target_id riattivato.", 'success');
                break;
        }
    }

    header('Location: tenants.php');
    exit;
}

// Paginazione
$page     = max(1, intval($_GET['p'] ?? 1));
$per_page = 20;
$search   = trim($_GET['q'] ?? '');

$where = $search ? "AND (u.email LIKE ? OR u.username LIKE ? OR t.id = ?)" : '';
$cnt_q = "SELECT COUNT(*) FROM saas_tenants t JOIN tb_utenti u ON u.id_utente = t.owner_user_id WHERE 1 $where";
$stm_cnt = mysqli_prepare($conn, $cnt_q);
if ($search) {
    $like = '%' . $search . '%';
    $sid  = intval($search) ?: -1;
    mysqli_stmt_bind_param($stm_cnt, 'ssi', $like, $like, $sid);
}
mysqli_stmt_execute($stm_cnt);
$total   = (int) mysqli_fetch_row(mysqli_stmt_get_result($stm_cnt))[0];
$pages   = max(1, (int)ceil($total / $per_page));
$page    = min($page, $pages);
$offset  = ($page - 1) * $per_page;

$list_q = "SELECT t.id AS tenant_id, t.status AS tenant_status, t.created_at,
                  u.username, u.email,
                  s.status AS sub_status, s.trial_ends_at, s.current_period_end, s.cancelled_at,
                  p.name AS plan_name, p.price_monthly
           FROM saas_tenants t
           JOIN tb_utenti u ON u.id_utente = t.owner_user_id
           LEFT JOIN saas_subscriptions s ON s.tenant_id = t.id
           LEFT JOIN saas_plans p ON p.id = s.plan_id
           WHERE 1 $where
           ORDER BY t.created_at DESC
           LIMIT ? OFFSET ?";
$stm_list = mysqli_prepare($conn, $list_q);
if ($search) {
    $like = '%' . $search . '%';
    $sid  = intval($search) ?: -1;
    mysqli_stmt_bind_param($stm_list, 'ssiis', $like, $like, $sid, $per_page, $offset);
} else {
    mysqli_stmt_bind_param($stm_list, 'ii', $per_page, $offset);
}
mysqli_stmt_execute($stm_list);
$tenants = mysqli_fetch_all(mysqli_stmt_get_result($stm_list), MYSQLI_ASSOC);
mysqli_close($conn);

$page_title   = 'Admin — Gestione Tenant';
$current_page = 'saas/admin/tenants.php';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="page-content">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="mb-0">
            <i class="bi bi-people text-danger me-2"></i>Gestione Tenant
        </h2>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2 me-1"></i> Dashboard
        </a>
    </div>

    <!-- Ricerca -->
    <form method="GET" class="mb-3 d-flex gap-2">
        <input type="text" name="q" class="form-control" placeholder="Cerca email, username o ID..." value="<?= e($search) ?>">
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
        <?php if ($search): ?>
        <a href="tenants.php" class="btn btn-outline-secondary">Reset</a>
        <?php endif; ?>
    </form>
    <p class="text-muted small mb-3"><?= $total ?> tenant trovati</p>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Utente</th>
                            <th>Email</th>
                            <th>Piano</th>
                            <th>Stato Sub.</th>
                            <th>Scadenza</th>
                            <th>Registrato</th>
                            <th class="text-center">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tenants as $t): ?>
                        <?php
                        $isPro     = strtolower($t['plan_name'] ?? '') === 'pro';
                        $subStatus = $t['sub_status'] ?? 'free';
                        $tenantSuspended = $t['tenant_status'] === 'suspended';
                        $badgeColor = match($subStatus) {
                            'active'    => $isPro ? 'success' : 'secondary',
                            'trial'     => 'info',
                            'cancelled' => 'warning',
                            'expired'   => 'danger',
                            default     => 'secondary',
                        };
                        $expiryDate = '';
                        if ($subStatus === 'trial') {
                            $expiryDate = $t['trial_ends_at'] ? date('d/m/Y', strtotime($t['trial_ends_at'])) : '—';
                        } elseif (!empty($t['current_period_end'])) {
                            $expiryDate = date('d/m/Y', strtotime($t['current_period_end']));
                        }
                        ?>
                        <tr class="<?= $tenantSuspended ? 'table-secondary text-muted' : '' ?>">
                            <td class="text-muted"><?= $t['tenant_id'] ?></td>
                            <td><?= e($t['username']) ?></td>
                            <td><?= e($t['email']) ?></td>
                            <td>
                                <span class="badge bg-<?= $isPro ? 'success' : 'secondary' ?>">
                                    <?= e($t['plan_name'] ?? 'Free') ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?= $badgeColor ?>">
                                    <?= ucfirst($subStatus) ?>
                                </span>
                                <?php if ($tenantSuspended): ?>
                                    <span class="badge bg-dark ms-1">Sospeso</span>
                                <?php endif; ?>
                            </td>
                            <td><small><?= $expiryDate ?></small></td>
                            <td><small><?= date('d/m/Y', strtotime($t['created_at'])) ?></small></td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <?php if (!$isPro || $subStatus !== 'active'): ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>">
                                        <input type="hidden" name="action" value="set_pro">
                                        <button type="submit" class="btn btn-outline-success btn-sm" title="Promo Pro 1 mese">
                                            <i class="bi bi-gem"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>">
                                        <input type="hidden" name="action" value="set_free">
                                        <button type="submit" class="btn btn-outline-warning btn-sm" title="Downgrade a Free">
                                            <i class="bi bi-arrow-down-circle"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if ($tenantSuspended): ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>">
                                        <input type="hidden" name="action" value="reactivate">
                                        <button type="submit" class="btn btn-outline-success btn-sm" title="Riattiva">
                                            <i class="bi bi-play-circle"></i>
                                        </button>
                                    </form>
                                    <?php elseif ($t['tenant_id'] > 1): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Sospendere il tenant #<?= $t['tenant_id'] ?>?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>">
                                        <input type="hidden" name="action" value="suspend">
                                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Sospendi">
                                            <i class="bi bi-pause-circle"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($tenants)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">Nessun tenant trovato</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginazione -->
            <?php if ($pages > 1): ?>
            <div class="card-footer py-2">
                <nav class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">Pagina <?= $page ?> di <?= $pages ?></small>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?p=<?= max(1, $page - 1) ?>&q=<?= urlencode($search) ?>">&laquo;</a>
                        </li>
                        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?p=<?= $i ?>&q=<?= urlencode($search) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?p=<?= min($pages, $page + 1) ?>&q=<?= urlencode($search) ?>">&raquo;</a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
