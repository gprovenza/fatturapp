<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'includes/tenant.php';

$conn      = getDBConnection();
$tenant_id = getTenantId();

$id_fattura = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id_fattura <= 0) {
    die('ID fattura non valido');
}

// Recupera info fattura + eventuale fattura elettronica (scoped al tenant)
$stmt = $conn->prepare(
    "SELECT f.*,
            fe.pdf_filename AS fe_pdf_filename,
            fe.xml_filename AS fe_xml_filename,
            fe.pdf_path     AS fe_pdf_path,
            fe.xml_path     AS fe_xml_path
     FROM tb_fatture f
     LEFT JOIN tb_fatture_elettroniche fe ON fe.numero_proforma = f.id_fattura
     WHERE f.id_fattura = ? AND f.tenant_id = ?"
);
$stmt->bind_param('ii', $id_fattura, $tenant_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    die('Fattura non trovata');
}

$fattura = $result->fetch_assoc();
$stmt->close();

// Determina quale file scaricare
$has_elettronica_pdf = !empty($fattura['fe_pdf_filename']) && !empty($fattura['fe_pdf_path']) && file_exists($fattura['fe_pdf_path']);
$has_elettronica_xml = !empty($fattura['fe_xml_filename']) && !empty($fattura['fe_xml_path']) && file_exists($fattura['fe_xml_path']);
$has_proforma = !empty($fattura['pdf_path']) && file_exists($fattura['pdf_path']);

// CASO 1: C'è fattura elettronica con PDF + XML -> crea ZIP
if ($has_elettronica_pdf && $has_elettronica_xml) {
    $zip_filename = 'Fattura_' . str_replace('/', '-', $fattura['numero_fattura']) . '.zip';
    $zip_path = 'fatture_elettroniche/' . $zip_filename;
    
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $zip->addFile($fattura['fe_pdf_path'], $fattura['fe_pdf_filename']);
        $zip->addFile($fattura['fe_xml_path'], $fattura['fe_xml_filename']);
        $zip->close();
        
        // Scarica lo ZIP
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        
        // Elimina il file ZIP temporaneo
        unlink($zip_path);
        exit;
    } else {
        die('Errore nella creazione dello ZIP. Verifica i permessi della directory fatture_elettroniche/');
    }
}

// CASO 2: C'è solo fattura elettronica PDF (senza XML)
if ($has_elettronica_pdf && !$has_elettronica_xml) {
    // Apri il PDF in una nuova finestra
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fattura['fe_pdf_filename'] . '"');
    header('Content-Length: ' . filesize($fattura['fe_pdf_path']));
    readfile($fattura['fe_pdf_path']);
    exit;
}

// CASO 3: C'è solo la pro-forma
if ($has_proforma && !$has_elettronica_pdf) {
    // Apri il PDF in una nuova finestra
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($fattura['pdf_path']) . '"');
    header('Content-Length: ' . filesize($fattura['pdf_path']));
    readfile($fattura['pdf_path']);
    exit;
}

// CASO 4: Nessun file disponibile
$conn->close();
die('Nessun file disponibile per questa fattura');
