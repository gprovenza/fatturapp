<?php
require_once 'auth.php';
require_once 'db.php';

$conn = getDBConnection();

$anno_corrente = max(2020, min(2035, intval($_GET['anno'] ?? date('Y'))));

// Recupera percentuale tasse dall'anagrafica (fallback a costante)
$tasse_pct = TAX_PERCENTAGE;
$res_tasse = mysqli_query($conn, 'SELECT tasse_percentuale FROM tb_anagrafiche LIMIT 1');
if ($row_tasse = mysqli_fetch_assoc($res_tasse)) {
    $tasse_pct = floatval($row_tasse['tasse_percentuale']);
}
$netto_pct = 1 - ($tasse_pct / 100);

// ============================================================
// QUERY MENSILE ottimizzata (JOIN invece di subquery correlata)
// ============================================================
// Fatturato per mese — query separata per evitare che il JOIN 1:N con tb_fatture_dettaglio
// moltiplichi totale_fattura per il numero di righe dettaglio di ogni fattura
$stmt_fatt = mysqli_prepare($conn,
    'SELECT mese, SUM(totale_fattura) AS totale_fatturato
     FROM tb_fatture
     WHERE anno = ?
     GROUP BY mese
     ORDER BY mese');
mysqli_stmt_bind_param($stmt_fatt, 'i', $anno_corrente);
mysqli_stmt_execute($stmt_fatt);
$result_fatturato = mysqli_stmt_get_result($stmt_fatt);

// Ore per mese — da tb_fatture_dettaglio
$stmt_ore_m = mysqli_prepare($conn,
    'SELECT f.mese, COALESCE(SUM(fd.ore_erogate), 0) AS totale_ore
     FROM tb_fatture_dettaglio fd
     JOIN tb_fatture f ON fd.id_fattura = f.id_fattura
     WHERE f.anno = ?
     GROUP BY f.mese');
mysqli_stmt_bind_param($stmt_ore_m, 'i', $anno_corrente);
mysqli_stmt_execute($stmt_ore_m);
$result_ore_mens = mysqli_stmt_get_result($stmt_ore_m);

$nomi_mesi = [1=>'Gennaio',2=>'Febbraio',3=>'Marzo',4=>'Aprile',5=>'Maggio',6=>'Giugno',
              7=>'Luglio',8=>'Agosto',9=>'Settembre',10=>'Ottobre',11=>'Novembre',12=>'Dicembre'];
$mesi_italiano = array_flip($nomi_mesi);

$dati_mesi = array_fill(1, 12, ['ore' => 0.0, 'fatturato' => 0.0]);
while ($row = mysqli_fetch_assoc($result_fatturato)) {
    $num = $mesi_italiano[$row['mese']] ?? 0;
    if ($num > 0) {
        $dati_mesi[$num]['fatturato'] = floatval($row['totale_fatturato']);
    }
}
mysqli_stmt_close($stmt_fatt);

while ($row = mysqli_fetch_assoc($result_ore_mens)) {
    $num = $mesi_italiano[$row['mese']] ?? 0;
    if ($num > 0) {
        $dati_mesi[$num]['ore'] = floatval($row['totale_ore']);
    }
}
mysqli_stmt_close($stmt_ore_m);

// ============================================================
// QUERY PROGETTO (prepared statement)
// ============================================================
$stmt_prj = mysqli_prepare($conn,
    'SELECT p.nome_progetto,
            SUM(fd.ore_erogate) AS totale_ore_fatturate,
            SUM(fd.subtotale)   AS totale_fatturato
     FROM tb_fatture_dettaglio fd
     JOIN tb_progetti p  ON fd.progetto_id = p.id_progetto
     JOIN tb_fatture f   ON fd.id_fattura  = f.id_fattura
     WHERE f.anno = ?
     GROUP BY p.id_progetto, p.nome_progetto
     ORDER BY totale_fatturato DESC');
mysqli_stmt_bind_param($stmt_prj, 'i', $anno_corrente);
mysqli_stmt_execute($stmt_prj);
$progetti_rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt_prj), MYSQLI_ASSOC);
mysqli_stmt_close($stmt_prj);

mysqli_close($conn);

// Totali anno
$totale_ore_anno       = array_sum(array_column($dati_mesi, 'ore'));
$totale_fatturato_anno = array_sum(array_column($dati_mesi, 'fatturato'));
$totale_netto_anno     = $totale_fatturato_anno * $netto_pct;

// Dati grafico JSON
$dati_grafico = [];
for ($m = 1; $m <= 12; $m++) {
    $dati_grafico[] = [
        'mese'      => $nomi_mesi[$m],
        'ore'       => round($dati_mesi[$m]['ore'], 2),
        'fatturato' => round($dati_mesi[$m]['fatturato'], 2),
        'netto'     => round($dati_mesi[$m]['fatturato'] * $netto_pct, 2),
    ];
}

$page_title   = 'Statistiche';
$current_page = 'statistiche_ore.php';
$extra_head   = ''; // Chart.js già incluso in footer.php
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="page-content">
    <!-- Header pagina -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <h2 class="mb-0"><i class="bi bi-graph-up text-danger me-2"></i>Statistiche <?= $anno_corrente ?></h2>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <label class="fw-semibold mb-0">Anno:</label>
            <select class="form-select form-select-sm d-inline-block w-auto"
                    onchange="location.href='?anno='+this.value">
                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $anno_corrente ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <a href="export_statistiche_pdf.php?anno=<?= $anno_corrente ?>"
               class="btn btn-sm btn-outline-danger" target="_blank">
                <i class="bi bi-file-earmark-pdf me-1"></i> Esporta PDF
            </a>
        </div>
    </div>

    <!-- Riepilogo annuale -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card stat-card border-0 bg-body-secondary">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0 fw-bold"><?= number_format($totale_ore_anno, 1, ',', '.') ?> h</h3>
                        <p class="mb-0 text-muted">Ore Fatturate</p>
                    </div>
                    <i class="bi bi-clock stat-icon text-primary opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card bg-success text-white">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0 fw-bold"><?= formatCurrency($totale_fatturato_anno) ?></h3>
                        <p class="mb-0 opacity-75">Fatturato Lordo</p>
                    </div>
                    <i class="bi bi-cash-stack stat-icon opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card bg-primary text-white">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0 fw-bold"><?= formatCurrency($totale_netto_anno) ?></h3>
                        <p class="mb-0 opacity-75">Netto Stimato (<?= number_format(100 - $tasse_pct, 0) ?>%)</p>
                    </div>
                    <i class="bi bi-wallet2 stat-icon opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Grafico mensile -->
    <div class="card page-card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-bar-chart-line me-2"></i>Andamento Mensile <?= $anno_corrente ?></h5>
        </div>
        <div class="card-body">
            <canvas id="graficoMensile" style="max-height:380px;"></canvas>
        </div>
    </div>

    <!-- Tabella mensile -->
    <div class="card page-card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-calendar3 me-2"></i>Dettaglio per Mese</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Mese</th>
                            <th class="text-end">Ore</th>
                            <th class="text-end">Lordo</th>
                            <th class="text-end">Netto (<?= number_format($tasse_pct, 0) ?>% tasse)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($m = 1; $m <= 12; $m++):
                            $d = $dati_mesi[$m];
                            $active = $d['fatturato'] > 0;
                        ?>
                        <tr <?= !$active ? 'class="text-muted"' : '' ?>>
                            <td><?= $nomi_mesi[$m] ?></td>
                            <td class="text-end"><?= $active ? number_format($d['ore'], 1, ',', '.') . ' h' : '—' ?></td>
                            <td class="text-end"><?= $active ? formatCurrency($d['fatturato']) : '—' ?></td>
                            <td class="text-end"><?= $active ? formatCurrency($d['fatturato'] * $netto_pct) : '—' ?></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td>TOTALE</td>
                            <td class="text-end"><?= number_format($totale_ore_anno, 1, ',', '.') ?> h</td>
                            <td class="text-end"><?= formatCurrency($totale_fatturato_anno) ?></td>
                            <td class="text-end"><?= formatCurrency($totale_netto_anno) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Per progetto -->
    <div class="card page-card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="bi bi-briefcase me-2"></i>Fatturato per Progetto</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Progetto</th>
                            <th class="text-end">Ore</th>
                            <th class="text-end">Importo</th>
                            <th>% sul Totale</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($progetti_rows)): ?>
                            <?php foreach ($progetti_rows as $row):
                                $pct = $totale_fatturato_anno > 0 ? ($row['totale_fatturato'] / $totale_fatturato_anno * 100) : 0;
                            ?>
                            <tr>
                                <td><strong><?= e($row['nome_progetto']) ?></strong></td>
                                <td class="text-end"><?= number_format($row['totale_ore_fatturate'], 1, ',', '.') ?> h</td>
                                <td class="text-end fw-semibold"><?= formatCurrency($row['totale_fatturato']) ?></td>
                                <td style="min-width:140px;">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height:16px;">
                                            <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                                        </div>
                                        <small><?= number_format($pct, 1) ?>%</small>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">Nessun dato disponibile</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($progetti_rows)): ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td>TOTALE</td>
                            <td class="text-end"><?= number_format($totale_ore_anno, 1, ',', '.') ?> h</td>
                            <td class="text-end"><?= formatCurrency($totale_fatturato_anno) ?></td>
                            <td>100%</td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$_json_grafico = json_encode($dati_grafico, JSON_HEX_TAG | JSON_HEX_AMP);
$extra_scripts = <<<JS
<script>
const datiGrafico = $_json_grafico;
const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.07)';
const textColor = isDark ? '#adb5bd' : '#666';

new Chart(document.getElementById('graficoMensile'), {
    type: 'bar',
    data: {
        labels: datiGrafico.map(d => d.mese),
        datasets: [
            {
                label: 'Ore Fatturate',
                data: datiGrafico.map(d => d.ore),
                backgroundColor: 'rgba(13,110,253,0.35)',
                borderColor: 'rgba(13,110,253,0.9)',
                borderWidth: 2,
                yAxisID: 'y-ore',
                order: 2,
                borderRadius: 4,
            },
            {
                label: 'Fatturato Lordo (€)',
                data: datiGrafico.map(d => d.fatturato),
                backgroundColor: 'rgba(25,135,84,0.15)',
                borderColor: 'rgba(25,135,84,1)',
                borderWidth: 2.5,
                type: 'line',
                yAxisID: 'y-euro',
                order: 1,
                tension: 0.35,
                pointRadius: 4,
                pointHoverRadius: 6,
            },
            {
                label: 'Netto Stimato (€)',
                data: datiGrafico.map(d => d.netto),
                backgroundColor: 'rgba(13,202,240,0.1)',
                borderColor: 'rgba(13,202,240,0.9)',
                borderWidth: 1.5,
                type: 'line',
                yAxisID: 'y-euro',
                order: 1,
                tension: 0.35,
                borderDash: [5,4],
                pointRadius: 3,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', labels: { color: textColor, usePointStyle: true } },
            tooltip: {
                callbacks: {
                    label: function(ctx) {
                        let l = ctx.dataset.label + ': ';
                        return ctx.dataset.yAxisID === 'y-ore' ? l + ctx.parsed.y + ' h' : l + '€ ' + ctx.parsed.y.toLocaleString('it-IT', {minimumFractionDigits:2});
                    }
                }
            }
        },
        scales: {
            'y-ore': {
                type: 'linear', position: 'left',
                title: { display: true, text: 'Ore', color: textColor },
                ticks: { callback: v => v + ' h', color: textColor },
                grid: { color: gridColor }
            },
            'y-euro': {
                type: 'linear', position: 'right',
                title: { display: true, text: 'Euro (€)', color: textColor },
                ticks: { callback: v => '€ ' + v.toLocaleString('it-IT'), color: textColor },
                grid: { drawOnChartArea: false }
            },
            x: { ticks: { color: textColor }, grid: { color: gridColor } }
        }
    }
});
</script>
JS;
require_once 'includes/footer.php';
?>
