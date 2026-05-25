<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'includes/functions.php';
require_once 'includes/tenant.php';

$conn          = getDBConnection();
$ruolo         = $_SESSION['ruolo'] ?? 'user';
$anno_corrente = (int)date('Y');
$tenant_id     = getTenantId();

// --- Statistiche rapide (prepared statements) ---

// Totale fatture
$stmt = $conn->prepare("SELECT COUNT(*) AS totale FROM tb_fatture WHERE tenant_id = ?");
$stmt->bind_param('i', $tenant_id);
$stmt->execute();
$totale_fatture = (int)$stmt->get_result()->fetch_assoc()['totale'];
$stmt->close();

// Fatturato anno corrente
$stmt = $conn->prepare("SELECT COALESCE(SUM(totale_fattura), 0) AS totale FROM tb_fatture WHERE tenant_id = ? AND anno = ?");
$stmt->bind_param('ii', $tenant_id, $anno_corrente);
$stmt->execute();
$guadagno_anno = (float)$stmt->get_result()->fetch_assoc()['totale'];
$stmt->close();

// Fatture non pagate
$stmt = $conn->prepare("SELECT COUNT(*) AS totale FROM tb_fatture WHERE tenant_id = ? AND pagata = 0");
$stmt->bind_param('i', $tenant_id);
$stmt->execute();
$fatture_non_pagate = (int)$stmt->get_result()->fetch_assoc()['totale'];
$stmt->close();

// Totale clienti
$stmt = $conn->prepare("SELECT COUNT(*) AS totale FROM tb_clienti WHERE tenant_id = ?");
$stmt->bind_param('i', $tenant_id);
$stmt->execute();
$totale_clienti = (int)$stmt->get_result()->fetch_assoc()['totale'];
$stmt->close();

// Totale progetti
$stmt = $conn->prepare("SELECT COUNT(*) AS totale FROM tb_progetti WHERE tenant_id = ?");
$stmt->bind_param('i', $tenant_id);
$stmt->execute();
$totale_progetti = (int)$stmt->get_result()->fetch_assoc()['totale'];
$stmt->close();

// Ore lavorate mese corrente
$mese_corrente = (int)date('n');
$stmt = $conn->prepare(
    "SELECT COALESCE(SUM(o.ore), 0) AS totale
     FROM tb_ore_lavoro o
     WHERE tenant_id = ? AND MONTH(o.data_lavoro) = ? AND YEAR(o.data_lavoro) = ? AND o.user_id = ?"
);
$stmt->bind_param('iiii', $tenant_id, $mese_corrente, $anno_corrente, $_SESSION['user_id']);
$stmt->execute();
$ore_mese = (float)$stmt->get_result()->fetch_assoc()['totale'];
$stmt->close();

// Ultime 5 fatture generate
$stmt = $conn->prepare(
    "SELECT f.numero_fattura, f.mese, f.anno, f.totale_fattura, f.pagata,
            c.denominazione AS cliente
     FROM tb_fatture f
     JOIN tb_clienti c ON c.id_cliente = f.cliente_id
     WHERE f.tenant_id = ?
     ORDER BY f.data_creazione DESC
     LIMIT 5"
);
$stmt->bind_param('i', $tenant_id);
$stmt->execute();
$ultime_fatture = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Guadagno netto stimato (lordo anno - tasse%)
$stmt = $conn->prepare(
    "SELECT COALESCE(a.tasse_percentuale, 35) AS tasse
     FROM tb_anagrafiche a
     WHERE tenant_id = ?
     LIMIT 1"
);
$stmt->bind_param('i', $tenant_id);
$stmt->execute();
$row_tasse = $stmt->get_result()->fetch_assoc();
$tasse_pct = $row_tasse ? (float)$row_tasse['tasse'] : 35.0;
$stmt->close();

$guadagno_netto = calcolaNetto($guadagno_anno, $tasse_pct);

$conn->close();

// --- Render ---
$page_title = 'Dashboard';
$current_page = 'index.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$nomi_mesi = [
    1=>'Gennaio',2=>'Febbraio',3=>'Marzo',4=>'Aprile',
    5=>'Maggio',6=>'Giugno',7=>'Luglio',8=>'Agosto',
    9=>'Settembre',10=>'Ottobre',11=>'Novembre',12=>'Dicembre'
];
?>

<div class="page-content">

    <!-- Intestazione -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold">Benvenuto, <?= htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></h4>
            <p class="text-muted mb-0 small"><?= date('l d F Y') ?> &mdash; <?= $nomi_mesi[$mese_corrente] ?> <?= $anno_corrente ?></p>
        </div>
        <?php if (in_array($ruolo, ['admin','user'])): ?>
        <div class="d-flex gap-2 flex-wrap">
            <a href="traccia_ore.php" class="btn btn-sm btn-primary">
                <i class="bi bi-clock-history me-1"></i> Traccia Ore
            </a>
            <a href="genera_fattura_form.php" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-file-earmark-plus me-1"></i> Nuova Fattura
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">

        <!-- Fatturato anno -->
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100" style="background: linear-gradient(135deg,#1e3a5f,#1d4ed8); color:#fff;">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <p class="text-white-50 small mb-1 fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.5px;">Fatturato <?= $anno_corrente ?></p>
                            <h3 class="mb-0 fw-bold"><?= formatCurrency($guadagno_anno) ?></h3>
                            <p class="text-white-50 small mb-0 mt-1">netto ~<?= formatCurrency($guadagno_netto) ?></p>
                        </div>
                        <div class="stat-icon opacity-25">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fatture totali -->
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100" style="background: linear-gradient(135deg,#065f46,#059669); color:#fff;">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <p class="text-white-50 small mb-1 fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.5px;">Fatture Emesse</p>
                            <h3 class="mb-0 fw-bold"><?= $totale_fatture ?></h3>
                            <?php if ($fatture_non_pagate > 0): ?>
                            <p class="small mb-0 mt-1" style="color:rgba(255,255,255,.7);">
                                <i class="bi bi-exclamation-circle me-1"></i><?= $fatture_non_pagate ?> non pagate
                            </p>
                            <?php else: ?>
                            <p class="text-white-50 small mb-0 mt-1"><i class="bi bi-check-circle me-1"></i>Tutte pagate</p>
                            <?php endif; ?>
                        </div>
                        <div class="stat-icon opacity-25">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ore mese -->
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100" style="background: linear-gradient(135deg,#92400e,#d97706); color:#fff;">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <p class="text-white-50 small mb-1 fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.5px;">Ore <?= $nomi_mesi[$mese_corrente] ?></p>
                            <h3 class="mb-0 fw-bold"><?= number_format($ore_mese, 1, ',', '.') ?><small class="fs-6 fw-normal"> h</small></h3>
                            <p class="text-white-50 small mb-0 mt-1">mese corrente</p>
                        </div>
                        <div class="stat-icon opacity-25">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Clienti / Progetti -->
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100" style="background: linear-gradient(135deg,#1e293b,#334155); color:#fff;">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <p class="text-white-50 small mb-1 fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.5px;">Clienti / Progetti</p>
                            <h3 class="mb-0 fw-bold"><?= $totale_clienti ?><small class="fs-6 fw-normal text-white-50"> / <?= $totale_progetti ?></small></h3>
                            <p class="text-white-50 small mb-0 mt-1">anagrafiche attive</p>
                        </div>
                        <div class="stat-icon opacity-25">
                            <i class="bi bi-people"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /KPI Cards -->

    <div class="row g-3">

        <!-- Accesso rapido -->
        <div class="col-lg-7">
            <div class="card page-card h-100">
                <div class="card-header bg-transparent d-flex align-items-center gap-2 py-3">
                    <i class="bi bi-grid-1x2 text-primary"></i>
                    <span>Accesso Rapido</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">

                        <?php if (in_array($ruolo, ['admin','user'])): ?>
                        <div class="col-6 col-sm-4">
                            <a href="traccia_ore.php" class="quick-link-card d-flex flex-column align-items-center justify-content-center text-decoration-none p-3 rounded-3 border h-100 text-center">
                                <i class="bi bi-clock-history fs-2 mb-2 text-primary"></i>
                                <span class="fw-semibold small">Traccia Ore</span>
                                <span class="text-muted" style="font-size:.72rem;">Registra lavoro</span>
                            </a>
                        </div>
                        <div class="col-6 col-sm-4">
                            <a href="genera_fattura_form.php" class="quick-link-card d-flex flex-column align-items-center justify-content-center text-decoration-none p-3 rounded-3 border h-100 text-center">
                                <i class="bi bi-file-earmark-plus fs-2 mb-2 text-success"></i>
                                <span class="fw-semibold small">Genera Pro-Forma</span>
                                <span class="text-muted" style="font-size:.72rem;">Nuova fattura</span>
                            </a>
                        </div>
                        <?php endif; ?>

                        <div class="col-6 col-sm-4">
                            <a href="upload_fattura.php" class="quick-link-card d-flex flex-column align-items-center justify-content-center text-decoration-none p-3 rounded-3 border h-100 text-center">
                                <i class="bi bi-cloud-upload fs-2 mb-2 text-warning"></i>
                                <span class="fw-semibold small">Carica Fattura</span>
                                <span class="text-muted" style="font-size:.72rem;">PDF / XML</span>
                            </a>
                        </div>

                        <div class="col-6 col-sm-4">
                            <a href="visualizza_fatture.php" class="quick-link-card d-flex flex-column align-items-center justify-content-center text-decoration-none p-3 rounded-3 border h-100 text-center">
                                <i class="bi bi-folder2-open fs-2 mb-2 text-info"></i>
                                <span class="fw-semibold small">Archivio Fatture</span>
                                <span class="text-muted" style="font-size:.72rem;">Storico completo</span>
                            </a>
                        </div>

                        <div class="col-6 col-sm-4">
                            <a href="statistiche_ore.php" class="quick-link-card d-flex flex-column align-items-center justify-content-center text-decoration-none p-3 rounded-3 border h-100 text-center">
                                <i class="bi bi-graph-up fs-2 mb-2 text-danger"></i>
                                <span class="fw-semibold small">Statistiche</span>
                                <span class="text-muted" style="font-size:.72rem;">Report annuale</span>
                            </a>
                        </div>

                        <?php if (in_array($ruolo, ['admin','user'])): ?>
                        <div class="col-6 col-sm-4">
                            <a href="gestione_clienti.php" class="quick-link-card d-flex flex-column align-items-center justify-content-center text-decoration-none p-3 rounded-3 border h-100 text-center">
                                <i class="bi bi-people fs-2 mb-2 text-secondary"></i>
                                <span class="fw-semibold small">Clienti</span>
                                <span class="text-muted" style="font-size:.72rem;">Anagrafiche</span>
                            </a>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>

        <!-- Ultime fatture -->
        <div class="col-lg-5">
            <div class="card page-card h-100">
                <div class="card-header bg-transparent d-flex align-items-center justify-content-between py-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-receipt text-primary"></i>
                        <span>Ultime Fatture</span>
                    </div>
                    <a href="visualizza_fatture.php" class="btn btn-xs btn-outline-primary" style="font-size:.75rem;padding:.2rem .55rem;">
                        Vedi tutte <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($ultime_fatture)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            Nessuna fattura ancora
                        </div>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($ultime_fatture as $f): ?>
                        <li class="list-group-item px-3 py-2">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center gap-2" style="min-width:0;">
                                    <span class="badge <?= $f['pagata'] ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?> rounded-pill"
                                          style="font-size:.65rem;white-space:nowrap;">
                                        <?= $f['pagata'] ? '<i class="bi bi-check"></i> Pagata' : '<i class="bi bi-hourglass-split"></i> Attesa' ?>
                                    </span>
                                    <div style="min-width:0;">
                                        <div class="fw-semibold small text-truncate" style="max-width:160px;">
                                            <?= htmlspecialchars($f['cliente'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <div class="text-muted" style="font-size:.72rem;">
                                            <?= htmlspecialchars($f['numero_fattura'], ENT_QUOTES, 'UTF-8') ?>
                                            &bull;
                                            <?= $nomi_mesi[(int)$f['mese']] ?? $f['mese'] ?> <?= $f['anno'] ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="fw-semibold small text-nowrap ms-2"><?= formatCurrency((float)$f['totale_fattura']) ?></span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /row -->
</div><!-- /page-content -->

<style>
.quick-link-card {
    transition: background 0.15s, transform 0.15s, box-shadow 0.15s;
    color: inherit;
}
.quick-link-card:hover {
    background: var(--bs-primary-bg-subtle) !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border-color: var(--bs-primary) !important;
}
</style>

<?php require_once 'includes/footer.php'; ?>
