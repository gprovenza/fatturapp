<?php
/**
 * Guard per le pagine admin SaaS.
 * Richiede: sessione attiva + tenant_id=1 + ruolo=admin.
 * Include auth.php (che include config.php e avvia la sessione).
 */
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/tenant.php';

if (!isSaasAdmin()) {
    header('Location: ' . APP_URL . 'index.php');
    exit;
}
