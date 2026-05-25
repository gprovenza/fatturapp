<?php
/**
 * Dashboard admin SaaS — metriche globali: MRR, tenants, trial in scadenza.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../db.php';

$conn = getDBConnection();

// ── Metriche principali ───────────────────────────────────────
// Tenants totali
$stm = mysqli_query($conn, 'SELECT COUNT(*) FROM saas_tenants');
$total_tenants = (int) mysqli_fetch_row($stm)[0];

// Abbonamenti attivi (paganti, non trial)
$stm = mysqli_prepare($conn,
    "SELECT COUNT(*) FROM saas_subscriptions s
     JOIN saas_plans p ON p.id = s.plan_id
     WHERE s.status = 'active' AND LOWER(p.name) = 'pro' AND s.trial_ends_at IS NULL");
mysqli_stmt_execute($stm);
$active_paid = (int) mysqli_fetch_row(mysqli_stmt_get_result($stm))[0];

// Trial attivi
$stm = mysqli_prepare($conn,
    "SELECT COUNT(*) FROM saas_subscriptions WHERE status = 'trial' AND trial_ends_at > NOW()");
mysqli_stmt_execute($stm);
$active_trials = (int) mysqli_fetch_row(mysqli_stmt_get_result($stm))[0];

// MRR (Monthly Recurring Revenue)
$stm = mysqli_prepare($conn,
    "SELECT COALESCE(SUM(p.price_monthly), 0)
     FROM saas_subscriptions s
     JOIN saas_plans p ON p.id = s.plan_id
     WHERE s.status = 'active' AND LOWER(p.name) = 'pro' AND s.trial_ends_at IS NULL");
mysqli_stmt_execute($stm);
$mrr = floatval(mysqli_fetch_row(mysqli_stmt_get_result($stm))[0]);

// Fatturato mese corrente
$stm = mysqli_prepare($conn,
    "SELECT COALESCE(SUM(amount), 0) FROM saas_payments
     WHERE status = 'completed' AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())");
mysqli_stmt_execute($stm);
$revenue_this_month = floatval(mysqli_fetch_row(mysqli_stmt_get_result($stm))[0]);

// Trial in scadenza nei prossimi 7 giorni
$stm = mysqli_prepare($conn,
    "SELECT t.id, t.owner_user_id, u.email, u.username,
            s.trial_ends_at, DATEDIFF(s.trial_ends_at, NOW()) AS days_left
     FROM saas_subscriptions s
     JOIN saas_tenants t ON t.id = s.tenant_id
     JOIN tb_utenti u ON u.id_utente = t.owner_user_id
     WHERE s.status = 'trial' AND s.trial_ends_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
     ORDER BY s.trial_ends_at ASC");
mysqli_stmt_execute($stm);
$expiring_trials = mysqli_fetch_all(mysqli_stmt_get_result($stm), MYSQLI_ASSOC);

// Abbonamenti scaduti di recente (ultimi 30 gg)
$stm = mysqli_prepare($conn,
    "SELECT COUNT(*) FROM saas_subscriptions WHERE status IN ('expired','cancelled') AND cancelled_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
mysqli_stmt_execute($stm);
$churned_30d = (int) mysqli_fetch_row(mysqli_stmt_get_result($stm))[0];

// Ultimi pagamenti
$stm = mysqli_prepare($conn,
    "SELECT sp.amount, sp.status, sp.created_at, u.email, u.username
     FROM saas_payments sp
     JOIN saas_tenants t ON t.id = sp.tenant_id
     JOIN tb_utenti u ON u.id_utente = t.owner_user_id
     ORDER BY sp.created_at DESC LIMIT 10");
mysqli_stmt_execute($stm);
$recent_payments = mysqli_fetch_all(mysqli_stmt_get_result($stm), MYSQLI_ASSOC);

mysqli_close($conn);

$page_title   = 'Admin SaaS — Dashboard';
$current_page = 'saas/admin/dashboard.php';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="page-content">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="mb-0">
            <i class="bi bi-speedometer2 text-danger me-2"></i>Admin SaaS
            <span class="badge bg-danger ms-2 fs-6">Piattaforma</span>
        </h2>
        <a href="tenants.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-people me-1"></i> Gestione Tenant
        </a>
    </div>

    <!-- KPI cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body">
                    <div class="fs-1 fw-bold text-primary"><?= $total_tenants ?></div>
                    <div class="text-muted small">Tenant Totali</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm text-center h-100 bg-success bg-opacity-10">
                <div class="card-body">
                    <div class="fs-1 fw-bold text-success"><?= $active_paid ?></div>
                    <div class="text-muted small">Abbonamenti Pro Attivi</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm text-center h-100 bg-info bg-opacity-10">
                <div class="card-body">
                    <div class="fs-1 fw-bold text-info"><?= $active_trials ?></div>
                    <div class="text-muted small">Trial Attivi</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm text-center h-100 bg-warning bg-opacity-10">
                <div class="card-body">
                    <div class="fs-1 fw-bold text-warning"><?= $churned_30d ?></div>
                    <div class="text-muted small">Churn ultimi 30gg</div>
                </div>
            </div>
        </div>
    </div>

    <!-- MRR + Revenue -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-muted mb-1 small text-uppercase">MRR (Recurring)</h6>
                    <div class="display-6 fw-bold text-success">
                        € <?= number_format($mrr, 2, ',', '.') ?>
                    </div>
                    <p class="text-muted mb-0 small mt-1">
                        <?= $active_paid ?> abbonati × € 7/mese
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-muted mb-1 small text-uppercase">Incassato questo mese</h6>
                    <div class="display-6 fw-bold text-primary">
                        € <?= number_format($revenue_this_month, 2, ',', '.') ?>
                    </div>
                    <p class="text-muted mb-0 small mt-1">Pagamenti completati <?= date('M Y') ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Trial in scadenza -->
    <?php if (!empty($expiring_trials)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-warning bg-opacity-25 border-0">
            <h5 class="mb-0 fw-bold">
                <i class="bi bi-hourglass-split me-2 text-warning"></i>Trial in scadenza (7 giorni)
            </h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Utente</th>
                        <th>Email</th>
                        <th>Scade il</th>
                        <th>Giorni rimasti</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expiring_trials as $t): ?>
                    <tr>
                        <td><?= e($t['username']) ?></td>
                        <td><?= e($t['email']) ?></td>
                        <td><?= date('d/m/Y', strtotime($t['trial_ends_at'])) ?></td>
                        <td>
                            <span class="badge bg-<?= $t['days_left'] <= 3 ? 'danger' : 'warning text-dark' ?>">
                                <?= $t['days_left'] ?> gg
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Ultimi pagamenti -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom">
            <h5 class="mb-0 fw-bold"><i class="bi bi-receipt me-2 text-secondary"></i>Ultimi Pagamenti</h5>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($recent_payments)): ?>
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Data</th>
                        <th>Utente</th>
                        <th>Email</th>
                        <th class="text-end">Importo</th>
                        <th>Stato</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_payments as $p): ?>
                    <tr>
                        <td class="text-nowrap"><small><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></small></td>
                        <td><?= e($p['username']) ?></td>
                        <td><?= e($p['email']) ?></td>
                        <td class="text-end fw-semibold">€ <?= number_format($p['amount'], 2, ',', '.') ?></td>
                        <td>
                            <span class="badge bg-<?= $p['status'] === 'completed' ? 'success' : 'warning' ?>">
                                <?= ucfirst($p['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-receipt fs-1 d-block mb-2 opacity-50"></i>
                Nessun pagamento registrato
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
