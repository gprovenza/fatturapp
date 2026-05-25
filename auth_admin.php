<?php
/**
 * Autenticazione admin: include auth.php e verifica ruolo admin.
 */
require_once __DIR__ . '/auth.php';

if (($_SESSION['ruolo'] ?? '') !== 'admin') {
    http_response_code(403);
    include __DIR__ . '/includes/header.php';
    echo '<div class="d-flex align-items-center justify-content-center" style="min-height:60vh;">';
    echo '<div class="text-center"><i class="bi bi-shield-lock-fill fs-1 text-danger mb-3 d-block"></i>';
    echo '<h3>Accesso Negato</h3><p class="text-muted">Solo gli amministratori possono accedere a questa pagina.</p>';
    echo '<a href="index.php" class="btn btn-primary">Torna alla Home</a></div></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
