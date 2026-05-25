<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'includes/tenant.php';
require 'fpdf/fpdf.php';

$conn      = getDBConnection();
$tenant_id = getTenantId();
$anno      = max(2020, min(2035, intval($_GET['anno'] ?? date('Y'))));

// Tasse
$tasse_pct = TAX_PERCENTAGE;
$_stm_tx = mysqli_prepare($conn, 'SELECT tasse_percentuale FROM tb_anagrafiche WHERE tenant_id = ? LIMIT 1');
mysqli_stmt_bind_param($_stm_tx, 'i', $tenant_id);
mysqli_stmt_execute($_stm_tx);
if ($row_tasse = mysqli_fetch_assoc(mysqli_stmt_get_result($_stm_tx))) {
    $tasse_pct = floatval($row_tasse['tasse_percentuale']);
}
mysqli_stmt_close($_stm_tx);
$netto_pct = 1 - ($tasse_pct / 100);

// Fatturato per mese
$stmt_fatt = mysqli_prepare($conn,
    'SELECT mese, SUM(totale_fattura) AS totale_fatturato
     FROM tb_fatture
     WHERE tenant_id = ? AND anno = ?
     GROUP BY mese
     ORDER BY mese');
mysqli_stmt_bind_param($stmt_fatt, 'ii', $tenant_id, $anno);
mysqli_stmt_execute($stmt_fatt);
$result_fatturato = mysqli_stmt_get_result($stmt_fatt);

// Ore per mese
$stmt_ore_m = mysqli_prepare($conn,
    'SELECT f.mese, COALESCE(SUM(fd.ore_erogate), 0) AS totale_ore
     FROM tb_fatture_dettaglio fd
     JOIN tb_fatture f ON fd.id_fattura = f.id_fattura
     WHERE f.tenant_id = ? AND f.anno = ?
     GROUP BY f.mese');
mysqli_stmt_bind_param($stmt_ore_m, 'ii', $tenant_id, $anno);
mysqli_stmt_execute($stmt_ore_m);
$result_ore_mens = mysqli_stmt_get_result($stmt_ore_m);

$nomi_mesi   = [1=>'Gennaio',2=>'Febbraio',3=>'Marzo',4=>'Aprile',5=>'Maggio',6=>'Giugno',
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

// Dati per progetto
$stmt_prj = mysqli_prepare($conn,
    'SELECT p.nome_progetto,
            SUM(fd.ore_erogate) AS totale_ore,
            SUM(fd.subtotale)   AS totale_fatturato
     FROM tb_fatture_dettaglio fd
     JOIN tb_progetti p ON fd.progetto_id = p.id_progetto
     JOIN tb_fatture f  ON fd.id_fattura  = f.id_fattura
     WHERE f.tenant_id = ? AND f.anno = ?
     GROUP BY p.id_progetto, p.nome_progetto
     ORDER BY totale_fatturato DESC');
mysqli_stmt_bind_param($stmt_prj, 'ii', $tenant_id, $anno);
mysqli_stmt_execute($stmt_prj);
$progetti_rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt_prj), MYSQLI_ASSOC);
mysqli_stmt_close($stmt_prj);
mysqli_close($conn);

$totale_ore_anno       = array_sum(array_column($dati_mesi, 'ore'));
$totale_fatturato_anno = array_sum(array_column($dati_mesi, 'fatturato'));
$totale_netto_anno     = $totale_fatturato_anno * $netto_pct;

function cv(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;
}

// ── FPDF ──────────────────────────────────────────────────────────────
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// Titolo
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, cv("Statistiche Fatturazione $anno"), 0, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 6, cv("Generato il " . date('d/m/Y H:i')), 0, 1, 'C');
$pdf->Ln(6);

// Riepilogo annuale
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, cv("Riepilogo Anno $anno"), 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(80, 6, cv("Ore Fatturate Totali:"), 0, 0);
$pdf->Cell(0, 6, number_format($totale_ore_anno, 1, ',', '.') . ' h', 0, 1);
$pdf->Cell(80, 6, cv("Fatturato Lordo:"), 0, 0);
$pdf->Cell(0, 6, cv("EUR " . number_format($totale_fatturato_anno, 2, ',', '.')), 0, 1);
$pdf->Cell(80, 6, cv("Netto Stimato (tasse " . number_format($tasse_pct, 0) . "%):"), 0, 0);
$pdf->Cell(0, 6, cv("EUR " . number_format($totale_netto_anno, 2, ',', '.')), 0, 1);
$pdf->Ln(4);

// Tabella mensile
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'Dettaglio per Mese', 0, 1, 'L');

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(50, 50, 50);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(45, 7, 'Mese', 1, 0, 'L', true);
$pdf->Cell(35, 7, 'Ore', 1, 0, 'R', true);
$pdf->Cell(50, 7, cv('Lordo (EUR)'), 1, 0, 'R', true);
$pdf->Cell(50, 7, cv('Netto (EUR)'), 1, 1, 'R', true);
$pdf->SetTextColor(0, 0, 0);

$pdf->SetFont('Arial', '', 9);
$fill = false;
for ($m = 1; $m <= 12; $m++) {
    $d    = $dati_mesi[$m];
    $netto = $d['fatturato'] * $netto_pct;
    $pdf->SetFillColor(245, 245, 245);
    $ore_str  = $d['ore'] > 0 ? number_format($d['ore'], 1, ',', '.') . ' h' : '-';
    $lord_str = $d['fatturato'] > 0 ? number_format($d['fatturato'], 2, ',', '.') : '-';
    $net_str  = $d['fatturato'] > 0 ? number_format($netto, 2, ',', '.') : '-';
    $pdf->Cell(45, 6, cv($nomi_mesi[$m]), 1, 0, 'L', $fill);
    $pdf->Cell(35, 6, $ore_str, 1, 0, 'R', $fill);
    $pdf->Cell(50, 6, $lord_str, 1, 0, 'R', $fill);
    $pdf->Cell(50, 6, $net_str, 1, 1, 'R', $fill);
    $fill = !$fill;
}

// Totale
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(45, 7, 'TOTALE', 1, 0, 'L', true);
$pdf->Cell(35, 7, number_format($totale_ore_anno, 1, ',', '.') . ' h', 1, 0, 'R', true);
$pdf->Cell(50, 7, number_format($totale_fatturato_anno, 2, ',', '.'), 1, 0, 'R', true);
$pdf->Cell(50, 7, number_format($totale_netto_anno, 2, ',', '.'), 1, 1, 'R', true);
$pdf->Ln(6);

// Tabella per progetto
if (!empty($progetti_rows)) {
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, cv('Fatturato per Progetto'), 0, 1, 'L');

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(50, 50, 50);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(70, 7, 'Progetto', 1, 0, 'L', true);
    $pdf->Cell(35, 7, 'Ore', 1, 0, 'R', true);
    $pdf->Cell(50, 7, cv('Importo (EUR)'), 1, 0, 'R', true);
    $pdf->Cell(25, 7, '%', 1, 1, 'R', true);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('Arial', '', 9);
    $fill = false;
    foreach ($progetti_rows as $prj) {
        $pct = $totale_fatturato_anno > 0
            ? number_format($prj['totale_fatturato'] / $totale_fatturato_anno * 100, 1) . '%'
            : '0%';
        $pdf->SetFillColor(245, 245, 245);
        $pdf->Cell(70, 6, cv($prj['nome_progetto']), 1, 0, 'L', $fill);
        $pdf->Cell(35, 6, number_format($prj['totale_ore'], 1, ',', '.') . ' h', 1, 0, 'R', $fill);
        $pdf->Cell(50, 6, number_format($prj['totale_fatturato'], 2, ',', '.'), 1, 0, 'R', $fill);
        $pdf->Cell(25, 6, $pct, 1, 1, 'R', $fill);
        $fill = !$fill;
    }
}

$pdf->Output('I', "Statistiche_$anno.pdf");
