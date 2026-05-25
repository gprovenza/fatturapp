<?php
require_once 'auth_admin.php';
require_once 'db.php';
require_once 'includes/tenant.php';

$conn      = getDBConnection();
$tenant_id = getTenantId();

$message      = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $prefisso    = trim($_POST['prefisso_fattura']    ?? '');
    $progressivo = max(1, intval($_POST['progressivo_fattura'] ?? 1));

    if (!preg_match('/^[A-Za-z0-9\-_]{1,10}$/', $prefisso)) {
        $message      = 'Prefisso non valido (max 10 caratteri alfanumerici).';
        $message_type = 'danger';
    } else {
        // Salva su saas_tenant_settings (fonte autoritativa)
        $upsert = $conn->prepare(
            "INSERT INTO saas_tenant_settings (tenant_id, chiave, valore) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valore = VALUES(valore)"
        );
        foreach (['prefisso_fattura' => $prefisso, 'progressivo_fattura' => (string)$progressivo] as $k => $v) {
            $upsert->bind_param('iss', $tenant_id, $k, $v);
            $upsert->execute();
        }
        $upsert->close();

        // Mantiene anche tb_impostazioni sincronizzata (solo tenant 1 legacy)
        if ($tenant_id === 1) {
            foreach (['prefisso_fattura' => $prefisso, 'progressivo_fattura' => (string)$progressivo] as $k => $v) {
                $s = $conn->prepare("UPDATE tb_impostazioni SET valore = ? WHERE chiave = ?");
                $s->bind_param('ss', $v, $k);
                $s->execute();
                $s->close();
            }
        }

        $message      = 'Impostazioni salvate con successo!';
        $message_type = 'success';
    }
}

// Recupera impostazioni correnti da saas_tenant_settings
$stm = $conn->prepare("SELECT chiave, valore FROM saas_tenant_settings WHERE tenant_id = ?");
$stm->bind_param('i', $tenant_id);
$stm->execute();
$impostazioni = [];
foreach ($stm->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $impostazioni[$r['chiave']] = $r['valore'];
}
$stm->close();

mysqli_close($conn);

$page_title   = 'Impostazioni';
$current_page = 'impostazioni.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>
<div class="content-wrapper">
<div class="container-fluid py-4">
  <div class="row justify-content-center">
    <div class="col-lg-6">
      <h2 class="fw-bold mb-4"><i class="bi bi-gear me-2 text-primary"></i>Impostazioni</h2>

      <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
          <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold">Numerazione Fatture</div>
        <div class="card-body">
          <form method="POST">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label class="form-label fw-semibold">Prefisso</label>
              <input type="text" name="prefisso_fattura" class="form-control" required maxlength="10"
                     value="<?= htmlspecialchars($impostazioni['prefisso_fattura'] ?? 'DOC', ENT_QUOTES, 'UTF-8') ?>">
              <div class="form-text">Es. DOC, FATTURA, INV (max 10 caratteri)</div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Prossimo progressivo</label>
              <input type="number" name="progressivo_fattura" class="form-control" required min="1"
                     value="<?= (int)($impostazioni['progressivo_fattura'] ?? 1) ?>">
              <div class="form-text">
                La prossima fattura sarà:
                <strong><?= htmlspecialchars(($impostazioni['prefisso_fattura'] ?? 'DOC') . ($impostazioni['progressivo_fattura'] ?? '1') . '-' . date('Y'), ENT_QUOTES, 'UTF-8') ?></strong>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Anno progressivo</label>
              <input type="text" class="form-control" disabled
                     value="<?= htmlspecialchars($impostazioni['anno_progressivo'] ?? date('Y'), ENT_QUOTES, 'UTF-8') ?>">
              <div class="form-text">Si resetta automaticamente ogni anno.</div>
            </div>
            <div class="alert alert-warning small">
              <i class="bi bi-exclamation-triangle me-1"></i>
              Modificare il progressivo potrebbe creare duplicati. Usa solo se sai cosa stai facendo.
            </div>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-save me-1"></i> Salva
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
</div>
<?php require_once 'includes/footer.php'; ?>
