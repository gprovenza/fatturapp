<?php
/**
 * Sidebar di navigazione
 *
 * Variabili attese:
 *   $current_page (string) - nome file corrente, es. 'traccia_ore.php'
 *
 * Usa $_SESSION['ruolo'] per i link condizionali.
 */

require_once __DIR__ . '/tenant.php';

$_current    = $current_page ?? basename($_SERVER['PHP_SELF']);
$_ruolo      = $_SESSION['ruolo'] ?? 'user';
$_plan       = getTenantPlan();
$_trialDays  = getTrialDaysLeft();
$_subActive  = isSubActive();
$_isSaasAdm  = isSaasAdmin();

function _sidebar_link(string $href, string $icon, string $label, string $current): string {
    $active = (basename($href) === $current) ? ' active' : '';
    return sprintf(
        '<a href="%s" class="nav-link%s"><i class="bi bi-%s"></i> %s</a>',
        htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
        $active,
        htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
    );
}
?>

<!-- Overlay mobile -->
<div id="sidebar-overlay" onclick="closeSidebar()"></div>

<!-- Topbar mobile -->
<nav id="topbar" class="navbar navbar-dark bg-dark px-3 py-2 align-items-center justify-content-between w-100">
    <button class="btn btn-sm btn-outline-light" onclick="openSidebar()">
        <i class="bi bi-list fs-5"></i>
    </button>
    <span class="navbar-brand mb-0 fw-bold">
        <i class="bi bi-receipt-cutoff text-primary"></i> Fatturazione
    </span>
    <a href="logout.php" class="btn btn-sm btn-outline-danger">
        <i class="bi bi-box-arrow-right"></i>
    </a>
</nav>

<!-- Sidebar -->
<nav id="sidebar">
    <!-- Brand -->
    <div class="sidebar-brand d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
            <span class="brand-icon"><i class="bi bi-receipt-cutoff"></i></span>
            <span style="font-size:.95rem;">Fatturazione</span>
        </div>
        <button id="darkModeToggle" title="Cambia tema" onclick="toggleDarkMode()">
            <i class="bi bi-moon-stars-fill" id="darkModeIcon"></i>
        </button>
    </div>

    <!-- DASHBOARD -->
    <div style="padding-bottom:0.4rem; margin-bottom:0.25rem; border-bottom:1px solid var(--sidebar-border);">
        <?= _sidebar_link('index.php', 'house-door', 'Dashboard', $_current) ?>
    </div>

    <!-- LAVORO -->
    <?php if (in_array($_ruolo, ['admin', 'user'])): ?>
    <div class="sidebar-section-label">Lavoro</div>
    <?= _sidebar_link('traccia_ore.php',      'clock-history',     'Traccia Ore',        $_current) ?>
    <?= _sidebar_link('genera_fattura_form.php','file-earmark-plus','Genera Pro-Forma',   $_current) ?>
    <?php endif; ?>

    <!-- FATTURE -->
    <div class="sidebar-section-label">Fatture</div>
    <?= _sidebar_link('upload_fattura.php',   'cloud-upload',      'Carica Fattura',     $_current) ?>
    <?= _sidebar_link('visualizza_fatture.php','folder2-open',     'Archivio Fatture',   $_current) ?>

    <!-- REPORT -->
    <div class="sidebar-section-label">Report</div>
    <?= _sidebar_link('statistiche_ore.php',  'graph-up',          'Statistiche',        $_current) ?>

    <!-- GESTIONE -->
    <?php if (in_array($_ruolo, ['admin', 'user'])): ?>
    <div class="sidebar-section-label">Gestione</div>
    <?= _sidebar_link('gestione_clienti.php', 'people',            'Clienti',            $_current) ?>
    <?= _sidebar_link('gestione_progetti.php','briefcase',         'Progetti',           $_current) ?>
    <?= _sidebar_link('gestione_piva.php',    'building',          'Anagrafiche P.IVA',  $_current) ?>
    <?php endif; ?>

    <!-- SISTEMA -->
    <div class="sidebar-section-label">Sistema</div>
    <?php if ($_ruolo === 'admin'): ?>
    <?= _sidebar_link('gestione_utenti.php',  'person-badge',      'Utenti',             $_current) ?>
    <?= _sidebar_link('impostazioni.php',     'gear',              'Impostazioni',       $_current) ?>
    <?php endif; ?>
    <?= _sidebar_link('cambia_password.php',  'key',               'Cambia Password',    $_current) ?>

    <!-- SAAS -->
    <div class="sidebar-section-label">Abbonamento</div>
    <?php
    // Percorso relativo verso saas/ (funziona sia dalla root che da sottodirectory)
    $_saas_base = str_repeat('../', max(0, substr_count(str_replace($_SERVER['DOCUMENT_ROOT'], '', $_SERVER['SCRIPT_FILENAME']), '/') - 1));
    ?>
    <?= _sidebar_link($_saas_base . 'saas/billing.php', 'credit-card', 'Abbonamento', basename($_SERVER['PHP_SELF'])) ?>
    <?php if ($_isSaasAdm): ?>
    <?= _sidebar_link($_saas_base . 'saas/admin/dashboard.php', 'speedometer2', 'Admin SaaS', basename($_SERVER['PHP_SELF'])) ?>
    <?php endif; ?>

    <!-- Banner trial / scaduto -->
    <?php if ($_plan['status'] === 'trial' && $_trialDays > 0): ?>
    <div style="margin:8px 8px 0;padding:8px 10px;background:rgba(37,99,235,.12);border-radius:8px;border:1px solid rgba(37,99,235,.25)">
      <div style="font-size:.72rem;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px">
        <i class="bi bi-clock-history me-1"></i>Trial gratuito
      </div>
      <div style="font-size:.8rem">Scade tra <strong><?= $_trialDays ?></strong> giorn<?= $_trialDays === 1 ? 'o' : 'i' ?></div>
      <a href="<?= htmlspecialchars($_saas_base . 'saas/billing.php', ENT_QUOTES, 'UTF-8') ?>"
         style="display:block;margin-top:6px;padding:4px 0;background:#2563eb;color:#fff;border-radius:5px;text-decoration:none;font-size:.74rem;font-weight:600;text-align:center">
        Attiva piano Pro →
      </a>
    </div>
    <?php elseif (!$_subActive && !$_isSaasAdm): ?>
    <div style="margin:8px 8px 0;padding:8px 10px;background:rgba(220,38,38,.1);border-radius:8px;border:1px solid rgba(220,38,38,.25)">
      <div style="font-size:.72rem;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.4px">
        <i class="bi bi-exclamation-circle me-1"></i>Abbonamento scaduto
      </div>
      <a href="<?= htmlspecialchars($_saas_base . 'saas/billing.php', ENT_QUOTES, 'UTF-8') ?>"
         style="display:block;margin-top:6px;padding:4px 0;background:#dc2626;color:#fff;border-radius:5px;text-decoration:none;font-size:.74rem;font-weight:600;text-align:center">
        Rinnova ora →
      </a>
    </div>
    <?php endif; ?>

    <!-- Footer sidebar -->
    <div class="sidebar-footer">
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="user-avatar"><?= strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)) ?></span>
            <div style="min-width:0;">
                <div class="user-name text-truncate"><?= htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                <span class="badge bg-<?= $_ruolo === 'admin' ? 'danger' : ($_ruolo === 'commercialista' ? 'info' : 'secondary') ?> text-uppercase" style="font-size:.6rem;letter-spacing:.4px;">
                    <?= htmlspecialchars($_ruolo, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php
                $planBadgeColor = $_plan['name'] === 'pro' ? '#2563eb' : '#64748b';
                $planLabel = strtoupper($_plan['name']);
                if ($_plan['status'] === 'trial') $planLabel .= ' TRIAL';
                ?>
                <span style="font-size:.58rem;font-weight:700;color:#fff;background:<?= $planBadgeColor ?>;padding:1px 5px;border-radius:4px;letter-spacing:.3px;">
                    <?= htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </div>
        <a href="logout.php" class="btn btn-sm btn-outline-danger w-100 mt-1">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
    </div>
</nav>

<!-- Wrapper contenuto principale -->
<div id="main-content">
