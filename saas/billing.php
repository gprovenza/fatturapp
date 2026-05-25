<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/tenant.php';

$conn      = getDBConnection();
$tenant_id = getTenantId();

// Carica dati subscription completi
$stm = mysqli_prepare($conn,
    'SELECT s.id, s.plan_id, s.status, s.trial_ends_at, s.current_period_start, s.current_period_end,
            s.paypal_subscription_id, s.cancelled_at,
            p.name AS plan_name, p.price_monthly AS price_eur, p.max_fatture_mese, p.max_clienti
     FROM saas_subscriptions s
     JOIN saas_plans p ON p.id = s.plan_id
     WHERE s.tenant_id = ?
     ORDER BY s.id DESC LIMIT 1');
mysqli_stmt_bind_param($stm, 'i', $tenant_id);
mysqli_stmt_execute($stm);
$sub = mysqli_fetch_assoc(mysqli_stmt_get_result($stm));

// Ultimi pagamenti
$stm_pay = mysqli_prepare($conn,
    'SELECT amount, status, provider_payment_id, created_at
     FROM saas_payments
     WHERE tenant_id = ?
     ORDER BY created_at DESC LIMIT 12');
mysqli_stmt_bind_param($stm_pay, 'i', $tenant_id);
mysqli_stmt_execute($stm_pay);
$payments = mysqli_fetch_all(mysqli_stmt_get_result($stm_pay), MYSQLI_ASSOC);

mysqli_close($conn);

$plan      = getTenantPlan();
$trialDays = getTrialDaysLeft();
$subActive = isSubActive();

$page_title  = 'Abbonamento';
$current_page = 'saas/billing.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-content">
    <h2 class="mb-4"><i class="bi bi-credit-card text-primary me-2"></i>Abbonamento</h2>

    <!-- Stato abbonamento corrente -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title fw-bold mb-3">
                        <i class="bi bi-person-badge me-2 text-primary"></i>Piano Attuale
                    </h5>
                    <?php
                    $planLabel = $sub['plan_name'] ?? 'Free';
                    $statusSub = $sub['status'] ?? 'active';
                    $isTrial   = ($statusSub === 'trial');
                    $isPro     = (strtolower($planLabel) === 'pro');
                    ?>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="badge bg-<?= $isPro ? 'success' : 'secondary' ?> fs-6 px-3 py-2">
                            <?= e($planLabel) ?>
                        </span>
                        <?php if ($isTrial): ?>
                            <span class="badge bg-info text-dark">
                                <i class="bi bi-hourglass-split me-1"></i>Trial — <?= $trialDays ?> giorni rimasti
                            </span>
                        <?php elseif ($statusSub === 'active'): ?>
                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Attivo</span>
                        <?php elseif ($statusSub === 'cancelled'): ?>
                            <span class="badge bg-warning text-dark"><i class="bi bi-x-circle me-1"></i>Cancellato</span>
                        <?php elseif ($statusSub === 'expired'): ?>
                            <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Scaduto</span>
                        <?php endif; ?>
                    </div>

                    <ul class="list-unstyled mb-3">
                        <?php $maxF = $sub['max_fatture_mese'] ?? null; $maxC = $sub['max_clienti'] ?? null; ?>
                        <li class="mb-1"><i class="bi bi-file-text me-2 text-muted"></i>
                            Fatture/mese: <strong><?= $maxF === null ? 'Illimitate' : $maxF ?></strong>
                        </li>
                        <li class="mb-1"><i class="bi bi-people me-2 text-muted"></i>
                            Clienti: <strong><?= $maxC === null ? 'Illimitati' : $maxC ?></strong>
                        </li>
                        <?php if ($isPro): ?>
                        <li class="mb-1"><i class="bi bi-currency-euro me-2 text-muted"></i>
                            Prezzo: <strong>€ <?= number_format(floatval($sub['price_eur'] ?? 7), 2, ',', '.') ?>/mese</strong>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <?php if ($isTrial || !$isPro): ?>
                    <a href="plans.php" class="btn btn-success">
                        <i class="bi bi-arrow-up-circle me-1"></i> Passa a Pro
                    </a>
                    <?php elseif ($statusSub === 'active' && !empty($sub['paypal_subscription_id']) && empty($sub['cancelled_at'])): ?>
                    <form method="POST" action="paypal/cancel-subscription.php"
                          onsubmit="return confirm('Sei sicuro di voler cancellare l\'abbonamento?')">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-x-circle me-1"></i> Cancella Abbonamento
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title fw-bold mb-3">
                        <i class="bi bi-calendar-check me-2 text-info"></i>Dettagli Periodo
                    </h5>
                    <?php if ($isTrial): ?>
                        <p class="mb-1 text-muted">Periodo di prova gratuito:</p>
                        <p class="fw-bold text-info fs-5 mb-3">
                            Scade il <?= date('d/m/Y', strtotime($sub['trial_ends_at'])) ?>
                        </p>
                        <div class="progress mb-2" style="height:8px">
                            <?php
                            $trialStart = strtotime('-30 days', strtotime($sub['trial_ends_at']));
                            $now = time();
                            $total = strtotime($sub['trial_ends_at']) - $trialStart;
                            $elapsed = $now - $trialStart;
                            $pct = max(0, min(100, round($elapsed / $total * 100)));
                            ?>
                            <div class="progress-bar bg-info" style="width:<?= $pct ?>%"></div>
                        </div>
                        <small class="text-muted"><?= $trialDays ?> giorni rimasti su 30</small>
                    <?php elseif (!empty($sub['current_period_end'])): ?>
                        <p class="mb-1 text-muted">Periodo corrente:</p>
                        <p class="mb-1">
                            <i class="bi bi-calendar me-1 text-muted"></i>
                            <?= date('d/m/Y', strtotime($sub['current_period_start'] ?? 'now')) ?>
                            &rarr;
                            <?= date('d/m/Y', strtotime($sub['current_period_end'])) ?>
                        </p>
                        <?php if (!empty($sub['cancelled_at'])): ?>
                        <p class="mt-2 text-warning">
                            <i class="bi bi-info-circle me-1"></i>
                            Cancellato il <?= date('d/m/Y', strtotime($sub['cancelled_at'])) ?>.
                            Accesso garantito fino alla fine del periodo.
                        </p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted">Nessun periodo attivo.</p>
                        <a href="plans.php" class="btn btn-success mt-2">
                            <i class="bi bi-rocket-takeoff me-1"></i> Attiva Piano Pro
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Storico pagamenti -->
    <?php if (!empty($payments)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom">
            <h5 class="mb-0 fw-bold">
                <i class="bi bi-receipt me-2 text-secondary"></i>Storico Pagamenti
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Data</th>
                            <th>Descrizione</th>
                            <th class="text-end">Importo</th>
                            <th>Stato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="text-nowrap"><small><?= date('d/m/Y', strtotime($p['created_at'])) ?></small></td>
                            <td><?= e($p['provider_payment_id'] ?? 'Piano Pro mensile') ?></td>
                            <td class="text-end fw-semibold">€ <?= number_format(floatval($p['amount']), 2, ',', '.') ?></td>
                            <td>
                                <?php $bs = match($p['status']) {
                                    'completed' => 'success',
                                    'pending'   => 'warning',
                                    default     => 'secondary',
                                }; ?>
                                <span class="badge bg-<?= $bs ?>"><?= ucfirst($p['status']) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-receipt fs-1 d-block mb-2 opacity-50"></i>
            Nessun pagamento registrato
        </div>
    </div>
    <?php endif; ?>

    <!-- GDPR -->
    <div class="mt-4">
        <details>
            <summary class="text-muted small" style="cursor:pointer">Opzioni Privacy e GDPR</summary>
            <div class="mt-2 p-3 border rounded bg-light">
                <p class="mb-2 small">
                    <i class="bi bi-shield-check me-1"></i>
                    I tuoi dati sono conservati su server EU. Puoi richiedere l'esportazione o la cancellazione in qualsiasi momento.
                </p>
                <a href="gdpr-export.php" class="btn btn-sm btn-outline-secondary me-2">
                    <i class="bi bi-download me-1"></i> Esporta miei dati
                </a>
                <a href="mailto:privacy@fatturapp.it" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash me-1"></i> Richiedi cancellazione account
                </a>
            </div>
        </details>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
