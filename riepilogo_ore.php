<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'includes/tenant.php';

$conn      = getDBConnection();
$tenant_id = getTenantId();

$mese_num = intval($_GET['mese'] ?? 0);
$anno = intval($_GET['anno'] ?? date('Y'));
$filtro_cliente = intval($_GET['cliente_id'] ?? 0); // Nuovo filtro

if ($mese_num < 1 || $mese_num > 12) {
    die('<div class="alert alert-danger m-4">Mese non valido.</div>');
}

$nomi_mesi = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
    5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
    9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
];

$mese = $nomi_mesi[$mese_num];
$primo_giorno = "$anno-" . str_pad($mese_num, 2, '0', STR_PAD_LEFT) . "-01";
$ultimo_giorno = date("Y-m-t", strtotime($primo_giorno));

// Recupera lista clienti per il filtro
$_stm_cl2 = mysqli_prepare($conn, 'SELECT id_cliente, denominazione FROM tb_clienti WHERE tenant_id = ? ORDER BY denominazione');
mysqli_stmt_bind_param($_stm_cl2, 'i', $tenant_id);
mysqli_stmt_execute($_stm_cl2);
$result_clienti = mysqli_stmt_get_result($_stm_cl2);

// Query principale ore con filtro cliente opzionale
$uid = (int)$_SESSION['user_id'];
if ($filtro_cliente > 0) {
    $stm_r = mysqli_prepare($conn,
        "SELECT p.id_progetto, p.nome_progetto, p.paga_oraria, p.tariffa_gruppo,
                c.denominazione as nome_cliente,
                SUM(CASE WHEN o.tipo_ore = 'singolo' THEN o.ore ELSE 0 END) as ore_singolo,
                SUM(CASE WHEN o.tipo_ore = 'gruppo'  THEN o.ore ELSE 0 END) as ore_gruppo,
                SUM(o.ore) as totale_ore,
                SUM(CASE WHEN o.tipo_ore = 'gruppo' THEN o.ore * p.tariffa_gruppo ELSE o.ore * p.paga_oraria END) as totale_lordo
         FROM tb_ore_lavoro o
         JOIN tb_progetti p ON o.progetto_id = p.id_progetto
         LEFT JOIN tb_clienti c ON p.id_cliente = c.id_cliente
         WHERE o.tenant_id = ? AND o.user_id = ? AND o.data_lavoro BETWEEN ? AND ? AND p.id_cliente = ?
         GROUP BY p.id_progetto, p.nome_progetto, p.paga_oraria, p.tariffa_gruppo, c.denominazione
         ORDER BY c.denominazione, p.nome_progetto");
    mysqli_stmt_bind_param($stm_r, 'iissi', $tenant_id, $uid, $primo_giorno, $ultimo_giorno, $filtro_cliente);
} else {
    $stm_r = mysqli_prepare($conn,
        "SELECT p.id_progetto, p.nome_progetto, p.paga_oraria, p.tariffa_gruppo,
                c.denominazione as nome_cliente,
                SUM(CASE WHEN o.tipo_ore = 'singolo' THEN o.ore ELSE 0 END) as ore_singolo,
                SUM(CASE WHEN o.tipo_ore = 'gruppo'  THEN o.ore ELSE 0 END) as ore_gruppo,
                SUM(o.ore) as totale_ore,
                SUM(CASE WHEN o.tipo_ore = 'gruppo' THEN o.ore * p.tariffa_gruppo ELSE o.ore * p.paga_oraria END) as totale_lordo
         FROM tb_ore_lavoro o
         JOIN tb_progetti p ON o.progetto_id = p.id_progetto
         LEFT JOIN tb_clienti c ON p.id_cliente = c.id_cliente
         WHERE o.tenant_id = ? AND o.user_id = ? AND o.data_lavoro BETWEEN ? AND ?
         GROUP BY p.id_progetto, p.nome_progetto, p.paga_oraria, p.tariffa_gruppo, c.denominazione
         ORDER BY c.denominazione, p.nome_progetto");
    mysqli_stmt_bind_param($stm_r, 'iiss', $tenant_id, $uid, $primo_giorno, $ultimo_giorno);
}
mysqli_stmt_execute($stm_r);
$result = mysqli_stmt_get_result($stm_r);

$totale_generale_ore = 0;
$totale_generale_lordo = 0;
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riepilogo Ore - <?= $mese ?> <?= $anno ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="bi bi-calendar-check"></i> Riepilogo: <?= $mese ?> <?= $anno ?></h4>
            <button type="button" class="btn btn-secondary" onclick="window.close()">Chiudi</button>
        </div>
        
        <!-- FILTRO CLIENTE -->
        <div class="card mb-4 shadow-sm">
            <div class="card-body bg-white">
                <form method="GET" class="row g-3 align-items-center">
                    <input type="hidden" name="mese" value="<?= $mese_num ?>">
                    <input type="hidden" name="anno" value="<?= $anno ?>">
                    
                    <div class="col-auto">
                        <label for="cliente_id" class="col-form-label fw-bold">Filtra per Cliente:</label>
                    </div>
                    <div class="col-auto">
                        <select name="cliente_id" id="cliente_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">Mostra Tutto</option>
                            <?php while($cl = mysqli_fetch_assoc($result_clienti)): ?>
                                <option value="<?= $cl['id_cliente'] ?>" <?= $filtro_cliente == $cl['id_cliente'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cl['denominazione']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Cliente</th>
                            <th>Progetto</th>
                            <th>Tariffa</th>
                            <th>Ore Totali</th>
                            <th>Importo Lordo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($result)): 
                                $totale_generale_ore += floatval($row['totale_ore']);
                                $totale_generale_lordo += floatval($row['totale_lordo']);
                            ?>
                                <tr>
                                    <td><span class="badge bg-primary"><?= htmlspecialchars($row['nome_cliente'] ?? 'N/A') ?></span></td>
                                    <td><strong><?= htmlspecialchars($row['nome_progetto']) ?></strong></td>
                                    <td>
                                        € <?= number_format($row['paga_oraria'], 2, ',', '.') ?>
                                        <?php if($row['tariffa_gruppo'] > 0) echo ' <small class="text-muted">(Grp: € '.number_format($row['tariffa_gruppo'], 2).')</small>'; ?>
                                    </td>
                                    <td><strong><?= number_format($row['totale_ore'], 2) ?> h</strong></td>
                                    <td class="text-end"><strong>€ <?= number_format($row['totale_lordo'], 2, ',', '.') ?></strong></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-4">Nessuna ora registrata per i criteri selezionati</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-group-divider">
                        <tr class="table-info">
                            <td colspan="3" class="text-end"><strong>TOTALE SELEZIONE</strong></td>
                            <td><strong><?= number_format($totale_generale_ore, 2) ?> h</strong></td>
                            <td class="text-end"><strong>€ <?= number_format($totale_generale_lordo, 2, ',', '.') ?></strong></td>
                        </tr>
                        <!-- Calcolo Netto Volante (basato su 35% standard per ora) -->
                        <tr class="table-success">
                            <td colspan="4" class="text-end"><strong>NETTO STIMATO (approx. 65%)</strong></td>
                            <td class="text-end"><strong>€ <?= number_format($totale_generale_lordo * 0.65, 2, ',', '.') ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
<?php mysqli_close($conn); ?>
