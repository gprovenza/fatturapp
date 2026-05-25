<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'includes/tenant.php';

$conn      = getDBConnection();
$tenant_id = getTenantId();
requireActiveSub();

// Carica dati per i select
$_stm_prj = mysqli_prepare($conn,
    'SELECT p.id_progetto, p.nome_progetto, c.denominazione AS cliente_nome
     FROM tb_progetti p
     JOIN tb_clienti c ON p.id_cliente = c.id_cliente
     WHERE p.tenant_id = ?
     ORDER BY c.denominazione, p.nome_progetto');
mysqli_stmt_bind_param($_stm_prj, 'i', $tenant_id);
mysqli_stmt_execute($_stm_prj);
$progetti = mysqli_fetch_all(mysqli_stmt_get_result($_stm_prj), MYSQLI_ASSOC);

$_stm_cli = mysqli_prepare($conn, 'SELECT id_cliente, denominazione FROM tb_clienti WHERE tenant_id = ? ORDER BY denominazione');
mysqli_stmt_bind_param($_stm_cli, 'i', $tenant_id);
mysqli_stmt_execute($_stm_cli);
$clienti = mysqli_fetch_all(mysqli_stmt_get_result($_stm_cli), MYSQLI_ASSOC);

// Pro-forme senza fattura elettronica collegata (massimo 3 mesi di anzianità)
$_stm_pfl = mysqli_prepare($conn,
    'SELECT f.id_fattura, f.numero_fattura, f.mese, f.anno, f.totale_fattura, f.cliente_id, c.denominazione AS cliente_nome
     FROM tb_fatture f
     JOIN tb_clienti c ON f.cliente_id = c.id_cliente
     LEFT JOIN tb_fatture_elettroniche fe ON fe.numero_proforma = f.id_fattura
     WHERE f.tenant_id = ? AND fe.id_fattura_elettronica IS NULL
       AND f.data_creazione >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
     ORDER BY f.anno DESC, f.data_creazione DESC');
mysqli_stmt_bind_param($_stm_pfl, 'i', $tenant_id);
mysqli_stmt_execute($_stm_pfl);
$proforma_libere = mysqli_fetch_all(mysqli_stmt_get_result($_stm_pfl), MYSQLI_ASSOC);

// ============================================================
// GESTIONE UPLOAD
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $numero_fattura = trim($_POST['numero_fattura'] ?? '');
    $totale_lordo   = floatval($_POST['totale_lordo'] ?? 0);
    $cliente_id     = intval($_POST['cliente_id'] ?? 0);
    $mese_form      = trim($_POST['mese'] ?? '');
    $anno_form      = intval($_POST['anno'] ?? 0);
    $note                  = trim($_POST['note'] ?? '');
    $proforma_collegata_id = intval($_POST['proforma_collegata_id'] ?? 0);
    $progetti_id           = $_POST['progetto_id'] ?? [];
    $ore_erogate    = $_POST['ore_erogate'] ?? [];

    if (empty($numero_fattura) || $totale_lordo <= 0 || !$cliente_id) {
        set_flash('Compila tutti i campi obbligatori.', 'danger');
        header('Location: upload_fattura.php');
        exit;
    }

    // Verifica duplicato (prepared statement)
    $stmt_chk = mysqli_prepare($conn, 'SELECT id_fattura_elettronica FROM tb_fatture_elettroniche WHERE numero_fattura = ? AND tenant_id = ?');
    mysqli_stmt_bind_param($stmt_chk, 'si', $numero_fattura, $tenant_id);
    mysqli_stmt_execute($stmt_chk);
    $dup = mysqli_stmt_get_result($stmt_chk)->fetch_assoc();
    mysqli_stmt_close($stmt_chk);

    if ($dup) {
        set_flash("Errore: Fattura \"$numero_fattura\" già caricata!", 'danger');
        header('Location: upload_fattura.php');
        exit;
    }

    // ---- Validazione e upload PDF ----
    if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] === UPLOAD_ERR_NO_FILE) {
        set_flash('Seleziona un file PDF.', 'danger');
        header('Location: upload_fattura.php');
        exit;
    }
    if ($_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        set_flash('Errore upload PDF (codice ' . $_FILES['pdf_file']['error'] . ').', 'danger');
        header('Location: upload_fattura.php');
        exit;
    }
    $max_bytes = MAX_UPLOAD_MB * 1024 * 1024;
    if ($_FILES['pdf_file']['size'] > $max_bytes) {
        set_flash('Il file PDF supera la dimensione massima di ' . MAX_UPLOAD_MB . 'MB.', 'danger');
        header('Location: upload_fattura.php');
        exit;
    }

    // Verifica MIME type — alcuni PDF da software fiscale vengono rilevati come octet-stream;
    // si accetta il file se il MIME è noto oppure l'estensione è .pdf
    $pdf_ext_check = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_pdf = $finfo ? finfo_file($finfo, $_FILES['pdf_file']['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    $allowed_pdf_mimes = ['application/pdf', 'application/x-pdf', 'application/acrobat', 'application/octet-stream'];
    if (!in_array($mime_pdf, $allowed_pdf_mimes, true) && $pdf_ext_check !== 'pdf') {
        set_flash('Il file selezionato non è un PDF valido (MIME: ' . htmlspecialchars($mime_pdf) . ').', 'danger');
        header('Location: upload_fattura.php');
        exit;
    }

    // Sanitizza nome file
    $safe_num  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $numero_fattura);
    $pdf_ext   = 'pdf';
    $pdf_filename = $safe_num . '.' . $pdf_ext;
    $pdf_path  = UPLOAD_DIR . $pdf_filename;

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0750, true);

    if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdf_path)) {
        set_flash('Impossibile salvare il file PDF. Controlla i permessi della directory.', 'danger');
        header('Location: upload_fattura.php');
        exit;
    }
    $pdf_path_rel = 'fatture_elettroniche/' . $pdf_filename;

    // ---- Upload XML opzionale ----
    $xml_filename = '';
    $xml_path_rel = '';
    $xml_path_abs = '';
    if (isset($_FILES['xml_file']) && $_FILES['xml_file']['error'] === UPLOAD_ERR_OK) {
        $finfo2   = finfo_open(FILEINFO_MIME_TYPE);
        $mime_xml = $finfo2 ? finfo_file($finfo2, $_FILES['xml_file']['tmp_name']) : '';
        if ($finfo2) finfo_close($finfo2);

        $allowed_xml = ['application/xml', 'text/xml', 'text/plain'];
        if (in_array($mime_xml, $allowed_xml, true)) {
            $xml_filename    = $safe_num . '.xml';
            $xml_path_abs    = UPLOAD_DIR . $xml_filename;
            $xml_path_rel    = 'fatture_elettroniche/' . $xml_filename;
            if (!move_uploaded_file($_FILES['xml_file']['tmp_name'], $xml_path_abs)) {
                $xml_filename = '';
                $xml_path_rel = '';
            }
        }
    }

    // ---- Cerca pro-forma collegata ----
    $row_proforma    = null;
    $numero_proforma = null;
    $proforma_exists = false;

    if ($proforma_collegata_id > 0) {
        // Selezione diretta dal dropdown (link affidabile per ID)
        $stmt_pf = mysqli_prepare($conn, 'SELECT id_fattura, totale_fattura FROM tb_fatture WHERE id_fattura = ? AND tenant_id = ?');
        mysqli_stmt_bind_param($stmt_pf, 'ii', $proforma_collegata_id, $tenant_id);
        mysqli_stmt_execute($stmt_pf);
        $row_proforma = mysqli_stmt_get_result($stmt_pf)->fetch_assoc();
        mysqli_stmt_close($stmt_pf);
    } else {
        // Fallback: cerca per corrispondenza numero fattura
        $stmt_pf = mysqli_prepare($conn, 'SELECT id_fattura, totale_fattura FROM tb_fatture WHERE numero_fattura = ? AND tenant_id = ?');
        mysqli_stmt_bind_param($stmt_pf, 'si', $numero_fattura, $tenant_id);
        mysqli_stmt_execute($stmt_pf);
        $row_proforma = mysqli_stmt_get_result($stmt_pf)->fetch_assoc();
        mysqli_stmt_close($stmt_pf);
    }

    if ($row_proforma) {
        $numero_proforma = $row_proforma['id_fattura'];
        $proforma_exists = true;
    }

    // ---- Inserisci fattura elettronica ----
    $stmt_ins = mysqli_prepare($conn,
        'INSERT INTO tb_fatture_elettroniche (tenant_id, numero_fattura, numero_proforma, pdf_filename, pdf_path, xml_filename, xml_path, uploaded_by, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt_ins, 'isissssss',
        $tenant_id, $numero_fattura, $numero_proforma, $pdf_filename, $pdf_path_rel,
        $xml_filename, $xml_path_rel, $_SESSION['user_id'], $note);

    if (!mysqli_stmt_execute($stmt_ins)) {
        set_flash('Errore durante il salvataggio nel database.', 'danger');
        header('Location: upload_fattura.php');
        exit;
    }
    $id_fe = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt_ins);

    if ($proforma_exists) {
        // Aggiorna totale se diverso
        $diff = abs(floatval($row_proforma['totale_fattura']) - $totale_lordo);
        if ($diff > 0.01) {
            $stmt_upd = mysqli_prepare($conn,
                'UPDATE tb_fatture SET totale_fattura=?, totale_prestazioni=? WHERE id_fattura=?');
            $tot_prest = $totale_lordo - MARCA_BOLLO;
            mysqli_stmt_bind_param($stmt_upd, 'ddi', $totale_lordo, $tot_prest, $numero_proforma);
            if (!mysqli_stmt_execute($stmt_upd)) {
                error_log('Errore aggiornamento totale proforma: ' . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt_upd);
            set_flash('Fattura caricata e totale pro-forma aggiornato a ' . formatCurrency($totale_lordo) . '.', 'success');
        } else {
            set_flash("Fattura elettronica caricata e collegata alla pro-forma \"" . e($numero_fattura) . "\".", 'success');
        }
    } else {
        // Crea record di tracciamento in tb_fatture (path senza proforma)
        $stmt_ana = mysqli_prepare($conn, 'SELECT id_anagrafica FROM tb_anagrafiche WHERE tenant_id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt_ana, 'i', $tenant_id);
        mysqli_stmt_execute($stmt_ana);
        $row_ana       = mysqli_stmt_get_result($stmt_ana)->fetch_assoc();
        $anagrafica_id = $row_ana['id_anagrafica'] ?? 1;
        mysqli_stmt_close($stmt_ana);

        $tot_prest   = $totale_lordo - MARCA_BOLLO;
        $marca_bollo = MARCA_BOLLO;

        mysqli_begin_transaction($conn);
        try {
            $stmt_fatt = mysqli_prepare($conn,
                'INSERT INTO tb_fatture (tenant_id, numero_fattura, anagrafica_id, cliente_id, mese, anno, totale_prestazioni, marca_bollo, totale_fattura, data_creazione, pdf_path)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL)');
            mysqli_stmt_bind_param($stmt_fatt, 'isiisiddd',
                $tenant_id, $numero_fattura, $anagrafica_id, $cliente_id, $mese_form, $anno_form,
                $tot_prest, $marca_bollo, $totale_lordo);
            if (!mysqli_stmt_execute($stmt_fatt)) {
                throw new RuntimeException('Errore INSERT tb_fatture: ' . mysqli_error($conn));
            }
            $nuovo_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt_fatt);

            // Inserisci dettagli progetti (opzionali)
            if (!empty($progetti_id) && $nuovo_id > 0) {
                $stmt_prog_q = mysqli_prepare($conn, 'SELECT paga_oraria FROM tb_progetti WHERE id_progetto = ? AND tenant_id = ?');
                $stmt_det    = mysqli_prepare($conn,
                    'INSERT INTO tb_fatture_dettaglio (tenant_id, id_fattura, progetto_id, ore_erogate, costo_orario, subtotale) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($progetti_id as $idx => $pid) {
                    $pid = intval($pid);
                    $ore = floatval($ore_erogate[$idx] ?? 0);
                    if ($pid <= 0 || $ore <= 0) continue;
                    mysqli_stmt_bind_param($stmt_prog_q, 'ii', $pid, $tenant_id);
                    mysqli_stmt_execute($stmt_prog_q);
                    $row_prog = mysqli_stmt_get_result($stmt_prog_q)->fetch_assoc();
                    if ($row_prog) {
                        $costo = floatval($row_prog['paga_oraria']);
                        $sub   = $ore * $costo;
                        mysqli_stmt_bind_param($stmt_det, 'iiiddd', $tenant_id, $nuovo_id, $pid, $ore, $costo, $sub);
                        mysqli_stmt_execute($stmt_det);
                    }
                }
                mysqli_stmt_close($stmt_prog_q);
                mysqli_stmt_close($stmt_det);
            }

            // Collega fattura elettronica al nuovo record
            $stmt_link = mysqli_prepare($conn, 'UPDATE tb_fatture_elettroniche SET numero_proforma=? WHERE id_fattura_elettronica=?');
            mysqli_stmt_bind_param($stmt_link, 'ii', $nuovo_id, $id_fe);
            if (!mysqli_stmt_execute($stmt_link)) {
                throw new RuntimeException('Errore link proforma: ' . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt_link);

            mysqli_commit($conn);
            set_flash('Fattura elettronica caricata! Record guadagno creato per ' . formatCurrency($totale_lordo) . '.', 'success');
        } catch (\Throwable $ex) {
            mysqli_rollback($conn);
            error_log($ex->getMessage());
            // Il file è già stato salvato su disco: lo eliminiamo per coerenza
            if (file_exists($pdf_path)) @unlink($pdf_path);
            if (!empty($xml_path_abs) && file_exists($xml_path_abs)) @unlink($xml_path_abs);
            // Rimuovi anche il record in tb_fatture_elettroniche (già inserito prima)
            $stmt_del_fe = mysqli_prepare($conn, 'DELETE FROM tb_fatture_elettroniche WHERE id_fattura_elettronica=?');
            mysqli_stmt_bind_param($stmt_del_fe, 'i', $id_fe);
            mysqli_stmt_execute($stmt_del_fe);
            mysqli_stmt_close($stmt_del_fe);
            set_flash('Errore durante il salvataggio del record: ' . $ex->getMessage(), 'danger');
            header('Location: upload_fattura.php');
            exit;
        }
    }

    header('Location: upload_fattura.php?highlight=' . $id_fe . '#archivio');
    exit;
}

// Archivio fatture elettroniche paginato
$archivio_page     = max(1, intval($_GET['archivio_page'] ?? 1));
$archivio_per_page = 10;

$_stm_cnt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM tb_fatture_elettroniche WHERE tenant_id = ?');
mysqli_stmt_bind_param($_stm_cnt, 'i', $tenant_id);
mysqli_stmt_execute($_stm_cnt);
$archivio_total   = (int) mysqli_fetch_row(mysqli_stmt_get_result($_stm_cnt))[0];
$archivio_pages   = max(1, (int)ceil($archivio_total / $archivio_per_page));
$archivio_page    = min($archivio_page, $archivio_pages);
$archivio_offset  = ($archivio_page - 1) * $archivio_per_page;

$stmt_arch = mysqli_prepare($conn,
    'SELECT fe.*, u.username,
            CASE WHEN fe.numero_proforma IS NOT NULL THEN 1 ELSE 0 END AS ha_proforma
     FROM tb_fatture_elettroniche fe
     JOIN tb_utenti u ON fe.uploaded_by = u.id_utente
     WHERE fe.tenant_id = ?
     ORDER BY fe.data_upload DESC
     LIMIT ? OFFSET ?');
mysqli_stmt_bind_param($stmt_arch, 'iii', $tenant_id, $archivio_per_page, $archivio_offset);
mysqli_stmt_execute($stmt_arch);
$archivio = mysqli_fetch_all(mysqli_stmt_get_result($stmt_arch), MYSQLI_ASSOC);
mysqli_stmt_close($stmt_arch);

mysqli_close($conn);

$mesi_nomi = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];

$page_title  = 'Carica Fattura Elettronica';
$current_page = 'upload_fattura.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="page-content">
    <h2 class="mb-4"><i class="bi bi-cloud-upload text-warning me-2"></i>Carica Fattura Elettronica</h2>

    <div class="card page-card mb-4">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0"><i class="bi bi-upload me-2"></i>Nuovo Caricamento</h5>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>

                <?php if (!empty($proforma_libere)): ?>
                <div class="alert alert-info d-flex align-items-start gap-2 mb-3 py-2">
                    <i class="bi bi-link-45deg fs-5 mt-1 flex-shrink-0"></i>
                    <div class="w-100">
                        <strong>Collega a una pro-forma esistente</strong>
                        <small class="d-block text-muted mb-1">I campi sottostanti verranno compilati automaticamente. Lascia vuoto per una fattura autonoma.</small>
                        <select name="proforma_collegata_id" id="proformaSelect" class="form-select form-select-sm">
                            <option value="">— Nessuna pro-forma (fattura autonoma) —</option>
                            <?php foreach ($proforma_libere as $pf): ?>
                            <option value="<?= $pf['id_fattura'] ?>"
                                    data-numero="<?= e($pf['numero_fattura']) ?>"
                                    data-cliente="<?= $pf['cliente_id'] ?>"
                                    data-mese="<?= e($pf['mese']) ?>"
                                    data-anno="<?= $pf['anno'] ?>"
                                    data-totale="<?= number_format(floatval($pf['totale_fattura']), 2, '.', '') ?>">
                                <?= e($pf['numero_fattura']) ?> — <?= e($pf['cliente_nome']) ?> — <?= e($pf['mese']) ?> <?= $pf['anno'] ?> — <?= formatCurrency($pf['totale_fattura']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="proforma_collegata_id" value="">
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Numero Fattura <span class="text-danger">*</span></label>
                        <input type="text" name="numero_fattura" class="form-control" required
                               placeholder="es. DOC1-2026 o 2026/001">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Cliente <span class="text-danger">*</span></label>
                        <select name="cliente_id" class="form-select" required>
                            <option value="">Seleziona cliente...</option>
                            <?php foreach ($clienti as $c): ?>
                            <option value="<?= $c['id_cliente'] ?>"><?= e($c['denominazione']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Mese <span class="text-danger">*</span></label>
                        <select name="mese" class="form-select" required>
                            <?php
                            $mese_prec_idx = ((int)date('n') - 2 + 12) % 12;
                            foreach ($mesi_nomi as $idx => $nm):
                                $sel = ($idx === $mese_prec_idx) ? 'selected' : '';
                            ?>
                            <option value="<?= e($nm) ?>" <?= $sel ?>><?= e($nm) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Anno <span class="text-danger">*</span></label>
                        <select name="anno" class="form-select" required>
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Totale Lordo (€) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">€</span>
                            <input type="number" name="totale_lordo" class="form-control" required step="0.01" min="0" placeholder="1234.56">
                        </div>
                    </div>
                </div>

                <h6 class="fw-semibold text-muted mb-2">
                    <i class="bi bi-briefcase me-1"></i> Progetti e Ore
                    <small class="fw-normal">(compilare solo se NON esiste già la pro-forma)</small>
                </h6>
                <div id="progetti-container">
                    <div class="row g-2 mb-2 progetto-row align-items-end">
                        <div class="col-md-5">
                            <select name="progetto_id[]" class="form-select">
                                <option value="">Seleziona progetto...</option>
                                <?php foreach ($progetti as $prj): ?>
                                <option value="<?= $prj['id_progetto'] ?>"><?= e($prj['cliente_nome']) ?> — <?= e($prj['nome_progetto']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="number" name="ore_erogate[]" class="form-control" min="0" step="0.1" placeholder="Ore erogate">
                        </div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-outline-success w-100 add-progetto">
                                <i class="bi bi-plus me-1"></i> Aggiungi riga
                            </button>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">File PDF <span class="text-danger">*</span></label>
                        <input type="file" name="pdf_file" class="form-control" accept=".pdf" required>
                        <small class="text-muted">Max <?= MAX_UPLOAD_MB ?>MB · Solo PDF</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">File XML <small class="text-muted">(opzionale)</small></label>
                        <input type="file" name="xml_file" class="form-control" accept=".xml">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Note</label>
                        <textarea name="note" class="form-control" rows="2" placeholder="Note opzionali..."></textarea>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-warning btn-lg fw-semibold">
                        <i class="bi bi-upload me-2"></i> Carica Fattura
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Archivio -->
    <h4 class="mb-3" id="archivio"><i class="bi bi-archive me-2"></i>Archivio Fatture Elettroniche</h4>
    <div class="card page-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Numero Fattura</th>
                            <th class="text-center">Pro-forma</th>
                            <th class="text-center">PDF</th>
                            <th class="text-center">XML</th>
                            <th>Data Upload</th>
                            <th>Utente</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($archivio)): ?>
                            <?php foreach ($archivio as $row): ?>
                            <tr data-id="<?= $row['id_fattura_elettronica'] ?>">
                                <td><strong><?= e($row['numero_fattura']) ?></strong></td>
                                <td class="text-center">
                                    <?= $row['ha_proforma'] ?
                                        '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Sì</span>' :
                                        '<span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> No</span>'
                                    ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($row['pdf_path'])): ?>
                                    <a href="<?= e($row['pdf_path']) ?>" class="btn btn-sm btn-outline-primary" download>
                                        <i class="bi bi-download"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($row['xml_path'])): ?>
                                    <a href="<?= e($row['xml_path']) ?>" class="btn btn-sm btn-outline-info" download>
                                        <i class="bi bi-download"></i>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap"><small><?= date('d/m/Y H:i', strtotime($row['data_upload'])) ?></small></td>
                                <td><small><?= e($row['username']) ?></small></td>
                                <td><small class="text-muted"><?= e($row['note']) ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">Nessuna fattura elettronica caricata</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($archivio_pages > 1): ?>
            <div class="card-footer py-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <small class="text-muted">
                        Pagina <?= $archivio_page ?> di <?= $archivio_pages ?>
                        &nbsp;·&nbsp; <?= $archivio_total ?> fatture totali
                    </small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $archivio_page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?archivio_page=<?= max(1, $archivio_page - 1) ?>#archivio">&laquo;</a>
                            </li>
                            <?php
                            $last_shown = 0;
                            $pages_to_show = [];
                            for ($i = 1; $i <= $archivio_pages; $i++) {
                                if ($i === 1 || $i === $archivio_pages || abs($i - $archivio_page) <= 2) {
                                    $pages_to_show[] = $i;
                                }
                            }
                            foreach ($pages_to_show as $i):
                                if ($last_shown > 0 && $i - $last_shown > 1): ?>
                                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                <?php endif; ?>
                                <li class="page-item <?= $archivio_page === $i ? 'active' : '' ?>">
                                    <a class="page-link" href="?archivio_page=<?= $i ?>#archivio"><?= $i ?></a>
                                </li>
                                <?php $last_shown = $i; endforeach; ?>
                            <li class="page-item <?= $archivio_page >= $archivio_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?archivio_page=<?= min($archivio_pages, $archivio_page + 1) ?>#archivio">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Progetti dinamici
const progettiOptions = `<?php foreach ($progetti as $prj): ?><option value="<?= $prj['id_progetto'] ?>"><?= e($prj['cliente_nome']) ?> — <?= e($prj['nome_progetto']) ?></option><?php endforeach; ?>`;

document.getElementById('progetti-container').addEventListener('click', function(e) {
    if (e.target.closest('.add-progetto')) {
        const newRow = document.createElement('div');
        newRow.className = 'row g-2 mb-2 progetto-row align-items-end';
        newRow.innerHTML = `
            <div class="col-md-5">
                <select name="progetto_id[]" class="form-select">
                    <option value="">Seleziona progetto...</option>
                    ${progettiOptions}
                </select>
            </div>
            <div class="col-md-4">
                <input type="number" name="ore_erogate[]" class="form-control" min="0" step="0.1" placeholder="Ore">
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-outline-danger w-100 remove-progetto">
                    <i class="bi bi-dash me-1"></i> Rimuovi
                </button>
            </div>`;
        this.appendChild(newRow);
    }
    if (e.target.closest('.remove-progetto')) {
        const rows = this.querySelectorAll('.progetto-row');
        if (rows.length > 1) e.target.closest('.progetto-row').remove();
    }
});

// Auto-fill da pro-forma selezionata
const pfSelect = document.getElementById('proformaSelect');
if (pfSelect) {
    pfSelect.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (!opt.value) return;
        document.querySelector('[name="numero_fattura"]').value = opt.dataset.numero || '';
        document.querySelector('[name="cliente_id"]').value = opt.dataset.cliente || '';
        document.querySelector('[name="totale_lordo"]').value = opt.dataset.totale || '';
        const meseSelect = document.querySelector('[name="mese"]');
        for (const o of meseSelect.options) { if (o.value === opt.dataset.mese) { o.selected = true; break; } }
        const annoSelect = document.querySelector('[name="anno"]');
        for (const o of annoSelect.options) { if (o.value === opt.dataset.anno) { o.selected = true; break; } }
    });
}

// Highlight riga dall'URL
const highlight = new URLSearchParams(location.search).get('highlight');
if (highlight) {
    const row = document.querySelector(`tr[data-id="${highlight}"]`);
    if (row) {
        row.scrollIntoView({behavior: 'smooth', block: 'center'});
        row.style.transition = 'background-color 0.3s';
        row.style.backgroundColor = 'rgba(255,193,7,0.25)';
        setTimeout(() => { row.style.backgroundColor = ''; }, 3000);
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
