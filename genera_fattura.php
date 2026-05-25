<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'includes/tenant.php';

// Solo admin e user possono generare fatture
if (!in_array($_SESSION['ruolo'] ?? '', ['admin', 'user'], true)) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: genera_fattura_form.php');
    exit;
}

csrf_verify();
requireActiveSub();

require 'fpdf/fpdf.php';

$conn      = getDBConnection();
$tenant_id = getTenantId();

// Verifica limite piano Free
if (!canCreate('fattura', $conn)) {
    set_flash('Hai raggiunto il limite di fatture mensili del piano Free. <a href="saas/billing.php">Passa a Pro →</a>', 'warning');
    header('Location: genera_fattura_form.php');
    exit;
}

// Recupero e validazione input
$anagrafica_id  = intval($_POST['anagrafica_id'] ?? 0);
$cliente_id     = intval($_POST['cliente_id']    ?? 0);
$mese           = trim($_POST['mese']  ?? '');
$anno           = intval($_POST['anno'] ?? 0);
$progetti_id    = $_POST['progetto_id']  ?? [];
$ore_erogate    = $_POST['ore_erogate'] ?? [];

if (!$anagrafica_id || !$cliente_id || empty($mese) || !$anno || empty($progetti_id)) {
    set_flash('Dati mancanti. Ricompila il modulo.', 'danger');
    header('Location: genera_fattura_form.php');
    exit;
}

// ======================================================================
// CALCOLO PROGRESSIVO FATTURA (da saas_tenant_settings)
// ======================================================================
$stm_cfg = $conn->prepare(
    "SELECT chiave, valore FROM saas_tenant_settings WHERE tenant_id = ? AND chiave IN ('prefisso_fattura','progressivo_fattura','anno_progressivo')"
);
$stm_cfg->bind_param('i', $tenant_id);
$stm_cfg->execute();
$cfg = [];
foreach ($stm_cfg->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $cfg[$r['chiave']] = $r['valore'];
}
$stm_cfg->close();

$prefisso   = $cfg['prefisso_fattura']    ?? 'DOC';
$progressivo = (int)($cfg['progressivo_fattura'] ?? 1);
$anno_cfg   = (int)($cfg['anno_progressivo']    ?? date('Y'));

// Reset progressivo se cambia l'anno
if ($anno_cfg !== $anno) {
    $progressivo = 1;
    $anno_cfg    = $anno;
}

$numero_fattura    = $prefisso . $progressivo . '-' . $anno;
$nuovo_progressivo = $progressivo + 1;

// ======================================================================
// RECUPERO ANAGRAFICA E CLIENTE (prepared statements)
// ======================================================================
$stmt_an = mysqli_prepare($conn, 'SELECT * FROM tb_anagrafiche WHERE id_anagrafica = ? AND tenant_id = ?');
mysqli_stmt_bind_param($stmt_an, 'ii', $anagrafica_id, $tenant_id);
mysqli_stmt_execute($stmt_an);
$row_anagrafica = mysqli_stmt_get_result($stmt_an)->fetch_assoc();
mysqli_stmt_close($stmt_an);

$stmt_cl = mysqli_prepare($conn, 'SELECT * FROM tb_clienti WHERE id_cliente = ? AND tenant_id = ?');
mysqli_stmt_bind_param($stmt_cl, 'ii', $cliente_id, $tenant_id);
mysqli_stmt_execute($stmt_cl);
$row_cliente = mysqli_stmt_get_result($stmt_cl)->fetch_assoc();
mysqli_stmt_close($stmt_cl);

if (!$row_anagrafica || !$row_cliente) {
    set_flash('Anagrafica o cliente non trovato.', 'danger');
    header('Location: genera_fattura_form.php');
    exit;
}

function convertiTesto(string $testo): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $testo) ?: $testo;
}

// ======================================================================
// CALCOLO DETTAGLI PROGETTI (prepared statements)
// ======================================================================
$totale_prestazioni_globale = 0.0;
$dettagli_progetti = [];

$stmt_prj = mysqli_prepare($conn, 'SELECT * FROM tb_progetti WHERE id_progetto = ? AND tenant_id = ?');

foreach ($progetti_id as $idx => $pid) {
    $pid = intval($pid);
    $ore = floatval($ore_erogate[$idx] ?? 0);

    if ($pid <= 0 || $ore <= 0) continue;

    mysqli_stmt_bind_param($stmt_prj, 'ii', $pid, $tenant_id);
    mysqli_stmt_execute($stmt_prj);
    $row_prj = mysqli_stmt_get_result($stmt_prj)->fetch_assoc();

    if (!$row_prj) continue;

    $costo_orario   = floatval($row_prj['paga_oraria']);
    $subtotale      = $ore * $costo_orario;
    $totale_prestazioni_globale += $subtotale;

    $dettagli_progetti[] = [
        'progetto_id'   => $pid,
        'nome_progetto' => $row_prj['nome_progetto'],
        'cup'           => $row_prj['CUP'] ?? '',
        'ore'           => $ore,
        'costo_orario'  => $costo_orario,
        'subtotale'     => $subtotale,
    ];
}
mysqli_stmt_close($stmt_prj);

if (empty($dettagli_progetti)) {
    set_flash('Nessun progetto valido. Controlla i dati inseriti.', 'danger');
    header('Location: genera_fattura_form.php');
    exit;
}

$marca_da_bollo = MARCA_BOLLO;
$totale_fattura = $totale_prestazioni_globale;

// ======================================================================
// SALVATAGGIO DATABASE
// ======================================================================
mysqli_begin_transaction($conn);

try {
    $stmt_ins = mysqli_prepare($conn,
        'INSERT INTO tb_fatture (numero_fattura, anagrafica_id, cliente_id, mese, anno, totale_prestazioni, marca_bollo, totale_fattura, data_creazione, tenant_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)');
    mysqli_stmt_bind_param($stmt_ins, 'siisidddi',
        $numero_fattura, $anagrafica_id, $cliente_id, $mese, $anno,
        $totale_prestazioni_globale, $marca_da_bollo, $totale_fattura, $tenant_id);
    mysqli_stmt_execute($stmt_ins);
    $id_fattura_db = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt_ins);

    $stmt_det = mysqli_prepare($conn,
        'INSERT INTO tb_fatture_dettaglio (id_fattura, progetto_id, ore_erogate, costo_orario, subtotale, tenant_id) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($dettagli_progetti as $det) {
        mysqli_stmt_bind_param($stmt_det, 'iidddi',
            $id_fattura_db, $det['progetto_id'], $det['ore'], $det['costo_orario'], $det['subtotale'], $tenant_id);
        mysqli_stmt_execute($stmt_det);
    }
    mysqli_stmt_close($stmt_det);

    // Aggiorna progressivo fattura in saas_tenant_settings
    $stm_upd_prog = $conn->prepare(
        "INSERT INTO saas_tenant_settings (tenant_id, chiave, valore) VALUES (?, 'progressivo_fattura', ?)
         ON DUPLICATE KEY UPDATE valore = VALUES(valore)"
    );
    $nuovo_prog_str = (string)$nuovo_progressivo;
    $stm_upd_prog->bind_param('is', $tenant_id, $nuovo_prog_str);
    $stm_upd_prog->execute();

    $stm_upd_anno = $conn->prepare(
        "INSERT INTO saas_tenant_settings (tenant_id, chiave, valore) VALUES (?, 'anno_progressivo', ?)
         ON DUPLICATE KEY UPDATE valore = VALUES(valore)"
    );
    $anno_str = (string)$anno_cfg;
    $stm_upd_anno->bind_param('is', $tenant_id, $anno_str);
    $stm_upd_anno->execute();

    mysqli_commit($conn);
} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log('Errore generazione fattura: ' . $e->getMessage());
    set_flash('Errore durante il salvataggio della fattura. Riprova.', 'danger');
    header('Location: genera_fattura_form.php');
    exit;
}

// ======================================================================
// GENERAZIONE PDF
// ======================================================================
if (!is_dir(PDF_DIR)) {
    mkdir(PDF_DIR, 0750, true);
}

$pdf_filename = $numero_fattura . '.pdf';
$pdf_path_rel = 'pdf/' . $pdf_filename;
$pdf_path_abs = PDF_DIR . $pdf_filename;

$pdf = new FPDF();
$pdf->AddPage();

// Intestazione fornitore / cliente
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(95, 5, convertiTesto($row_anagrafica['denominazione']), 0, 0, 'L');
$pdf->Cell(95, 5, convertiTesto($row_cliente['denominazione']), 0, 1, 'R');

$pdf->SetFont('Arial', '', 10);
$pdf->Cell(95, 5, convertiTesto($row_anagrafica['indirizzo'] ?? ''), 0, 0, 'L');
$pdf->Cell(95, 5, convertiTesto($row_cliente['indirizzo'] ?? ''), 0, 1, 'R');

$pdf->Cell(95, 5, convertiTesto(($row_anagrafica['cap'] ?? '') . ' - ' . ($row_anagrafica['citta'] ?? '') . ' (' . ($row_anagrafica['provincia'] ?? '') . ')'), 0, 0, 'L');
$pdf->Cell(95, 5, convertiTesto(($row_cliente['cap'] ?? '') . ' - ' . ($row_cliente['citta'] ?? '') . ' (' . ($row_cliente['provincia'] ?? '') . ')'), 0, 1, 'R');

$pdf->Cell(95, 5, 'P.IVA: ' . convertiTesto($row_anagrafica['partita_iva'] ?? ''), 0, 0, 'L');
$pdf->Cell(95, 5, 'P.IVA: ' . convertiTesto($row_cliente['partita_iva'] ?? ''), 0, 1, 'R');

$pdf->Cell(95, 5, 'CF: ' . convertiTesto($row_anagrafica['codice_fiscale'] ?? ''), 0, 0, 'L');
$pdf->Cell(95, 5, 'CF: ' . convertiTesto($row_cliente['codice_fiscale'] ?? ''), 0, 1, 'R');

$pdf->Cell(95, 5, 'Codice PR: ' . convertiTesto($row_anagrafica['PR'] ?? ''), 0, 0, 'L');
$pdf->Cell(95, 5, 'Codice destinatario (SDI): ' . convertiTesto($row_cliente['SDI'] ?? ''), 0, 1, 'R');

$pdf->Ln(10);

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(75, 5, 'Fattura Pro Forma - Periodo di riferimento: ', 0, 0, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 5, convertiTesto($mese) . ' ' . $anno, 0, 1, 'L');

$pdf->Ln(10);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 5, 'Oneri per prestazioni effettuate', 0, 1, 'C');
$pdf->Ln(10);

$sep = '--------------------------------------------------------------------------------------------------------------------------------------------------------------';
foreach ($dettagli_progetti as $det) {
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, $sep, 0, 1, 'L');

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 5, 'Servizio:', 0, 1, 'L');

    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, convertiTesto($det['nome_progetto']), 0, 1, 'L');
    $pdf->Cell(0, 5, 'CUP: ' . convertiTesto($det['cup']), 0, 1, 'L');
    $pdf->Ln(5);
    $pdf->Cell(0, 5, 'Ore lavorate: ' . $det['ore'] . ' ore', 0, 1, 'L');
    $pdf->Cell(0, 5, 'Costo orario: ' . chr(128) . ' ' . number_format($det['costo_orario'], 2, ',', '.'), 0, 1, 'L');
    $pdf->Ln(5);
    $pdf->Cell(0, 5, 'Totale prestazioni per questo progetto: ' . chr(128) . ' ' . number_format($det['subtotale'], 2, ',', '.'), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, $sep, 0, 1, 'L');
}

$pdf->Ln(10);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 5, 'Totale prestazioni complessive: ' . chr(128) . ' ' . number_format($totale_prestazioni_globale, 2, ',', '.'), 0, 1, 'L');
$pdf->Cell(0, 5, 'Marca da bollo: ' . chr(128) . ' ' . number_format($marca_da_bollo, 2, ',', '.'), 0, 1, 'L');
$pdf->Cell(0, 5, 'TOTALE FATTURA: ' . chr(128) . ' ' . number_format($totale_fattura, 2, ',', '.'), 0, 1, 'L');

$pdf->Ln(40);
$pdf->SetFont('Arial', 'I', 8);
$pdf->MultiCell(0, 5, convertiTesto("Operazione effettuata ai sensi dell'art. 1, commi da 54 a 89, della legge n. 190/2014 così come modificato dalla legge n. 208/2015. Si richiede la non applicazione della ritenuta alla fonte a titolo d'acconto ai sensi dell'art. 1 comma 67 della legge n. 190/2014. Imposta da bollo da 2 euro assolta sull'originale per importi superiori di 77,74."));

// Salva PDF su disco
$pdf->Output('F', $pdf_path_abs);

// Aggiorna pdf_path nel database
$stmt_upd = mysqli_prepare($conn, 'UPDATE tb_fatture SET pdf_path = ? WHERE id_fattura = ?');
mysqli_stmt_bind_param($stmt_upd, 'si', $pdf_path_rel, $id_fattura_db);
mysqli_stmt_execute($stmt_upd);
mysqli_stmt_close($stmt_upd);

mysqli_close($conn);

// Mostra il PDF nel browser
$pdf->Output('I', $pdf_filename);
