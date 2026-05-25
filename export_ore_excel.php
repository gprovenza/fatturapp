<?php
require_once 'auth.php';
require_once 'db.php';

$mese = intval($_GET['mese'] ?? date('n'));
$anno = intval($_GET['anno'] ?? date('Y'));

$conn = getDBConnection();

$primo_giorno = "$anno-" . str_pad($mese, 2, '0', STR_PAD_LEFT) . "-01";
$ultimo_giorno = date("Y-m-t", strtotime($primo_giorno));

$stmt = $conn->prepare(
    "SELECT o.data_lavoro, p.nome_progetto, o.tipo_ore, o.ore, o.note,
            CASE WHEN o.tipo_ore = 'gruppo' THEN p.tariffa_gruppo ELSE p.paga_oraria END AS tariffa,
            CASE WHEN o.tipo_ore = 'gruppo' THEN (o.ore * p.tariffa_gruppo) ELSE (o.ore * p.paga_oraria) END AS importo
     FROM tb_ore_lavoro o
     JOIN tb_progetti p ON o.progetto_id = p.id_progetto
     WHERE o.user_id = ? AND o.data_lavoro BETWEEN ? AND ?
     ORDER BY o.data_lavoro, p.nome_progetto"
);
$user_id = (int)$_SESSION['user_id'];
$stmt->bind_param('iss', $user_id, $primo_giorno, $ultimo_giorno);
$stmt->execute();
$result = $stmt->get_result();

$nomi_mesi = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
    5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
    9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
];

// Header per download CSV (compatibile Excel)
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="ore_' . $nomi_mesi[$mese] . '_' . $anno . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// BOM UTF-8 per Excel
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Intestazioni
fputcsv($output, ['Registrazione Ore - ' . $nomi_mesi[$mese] . ' ' . $anno], ';');
fputcsv($output, [], ';');
fputcsv($output, ['Data', 'Progetto', 'Tipo', 'Ore', 'Tariffa €', 'Importo €', 'Note'], ';');

$totale_ore = 0;
$totale_importo = 0;

while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        date('d/m/Y', strtotime($row['data_lavoro'])),
        $row['nome_progetto'],
        ucfirst($row['tipo_ore']),
        number_format($row['ore'], 2, ',', ''),
        number_format($row['tariffa'], 2, ',', ''),
        number_format($row['importo'], 2, ',', ''),
        $row['note']
    ], ';');
    
    $totale_ore += $row['ore'];
    $totale_importo += $row['importo'];
}

fputcsv($output, [], ';');
fputcsv($output, [
    'TOTALE',
    '',
    '',
    number_format($totale_ore, 2, ',', ''),
    '',
    number_format($totale_importo, 2, ',', ''),
    ''
], ';');

$ritenute = $totale_importo * 0.35;
$netto = $totale_importo - $ritenute;

fputcsv($output, [], ';');
fputcsv($output, ['Totale Lordo', number_format($totale_importo, 2, ',', '') . ' €'], ';');
fputcsv($output, ['Ritenute 35%', number_format($ritenute, 2, ',', '') . ' €'], ';');
fputcsv($output, ['Totale Netto', number_format($netto, 2, ',', '') . ' €'], ';');

fclose($output);
$stmt->close();
$conn->close();
exit;
?>
