<?php
require_once 'auth.php';
require_once 'db.php';

csrf_verify();

if (!isset($_POST['fatture']) || !is_array($_POST['fatture'])) {
    header("Location: visualizza_fatture.php");
    exit;
}

$fatture_ids = array_map('intval', $_POST['fatture']);

if (empty($fatture_ids)) {
    header("Location: visualizza_fatture.php");
    exit;
}

$conn = getDBConnection();

// Recupera i PDF
$ids_string = implode(',', $fatture_ids);
$query = "SELECT pdf_path, numero_fattura FROM tb_fatture WHERE id_fattura IN ($ids_string)";
$result = mysqli_query($conn, $query);

$files = [];
while ($row = mysqli_fetch_assoc($result)) {
    if (file_exists($row['pdf_path'])) {
        $files[] = [
            'path' => $row['pdf_path'],
            'name' => "fattura_" . str_replace('/', '-', $row['numero_fattura']) . ".pdf"
        ];
    }
}

mysqli_close($conn);

if (empty($files)) {
    die("Nessun PDF trovato");
}

// Se è solo un file, scaricalo direttamente
if (count($files) == 1) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $files[0]['name'] . '"');
    header('Content-Length: ' . filesize($files[0]['path']));
    readfile($files[0]['path']);
    exit;
}

// Altrimenti crea un ZIP
$zip_filename = 'fatture_' . date('Y-m-d_His') . '.zip';
$zip_path = 'pdf/' . $zip_filename;

$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
    die("Impossibile creare il file ZIP");
}

foreach ($files as $file) {
    $zip->addFile($file['path'], $file['name']);
}

$zip->close();

// Download del ZIP
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
header('Content-Length: ' . filesize($zip_path));

readfile($zip_path);

// Elimina il file ZIP temporaneo
unlink($zip_path);

exit;
?>
