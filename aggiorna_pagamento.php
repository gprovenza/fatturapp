<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'includes/tenant.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id_fattura = intval($_POST['id_fattura']);
    $pagata = intval($_POST['pagata']);
    $data_pagamento = $pagata ? date('Y-m-d') : null;
    $tenant_id = getTenantId();

    $conn = getDBConnection();

    $stmt = mysqli_prepare($conn, "UPDATE tb_fatture SET pagata = ?, data_pagamento = ? WHERE id_fattura = ? AND tenant_id = ?");
    mysqli_stmt_bind_param($stmt, "isii", $pagata, $data_pagamento, $id_fattura, $tenant_id);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'data_pagamento' => $data_pagamento]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
}
?>
