<?php
/**
 * Footer condiviso
 *
 * - Chiude #main-content aperto da sidebar.php
 * - Mostra flash messages come Toast Bootstrap
 * - Include Bootstrap JS e Chart.js
 */
require_once __DIR__ . '/alerts.php';
$_flash_messages = get_flash_messages();
?>

    <!-- Page footer bar -->
    <footer class="py-2 px-4 border-top" style="font-size:0.8rem; color: var(--bs-secondary-color);">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
            <span><i class="bi bi-person-circle"></i>
                <?= htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                <?php if (!empty($_SESSION['ruolo'])): ?>
                <span class="badge bg-<?= $_SESSION['ruolo'] === 'admin' ? 'danger' : 'secondary' ?> ms-1">
                    <?= htmlspecialchars(ucfirst($_SESSION['ruolo']), ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php endif; ?>
            </span>
            <span>© <?= date('Y') ?> Sistema Fatturazione</span>
            <span><i class="bi bi-calendar3"></i> <?= date('d/m/Y H:i') ?></span>
        </div>
    </footer>

</div><!-- /main-content -->

<!-- Toast container -->
<div id="toast-container"></div>

<?php if (!empty($_flash_messages)): ?>
<script>
window._flashMessages = <?= json_encode($_flash_messages, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<?php endif; ?>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<script>
/* ============================================================
   SIDEBAR MOBILE
============================================================ */
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('sidebar-overlay').classList.add('show');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebar-overlay').classList.remove('show');
}

/* ============================================================
   DARK MODE
============================================================ */
function toggleDarkMode() {
    const html  = document.documentElement;
    const current = html.getAttribute('data-bs-theme') || 'light';
    const next  = current === 'light' ? 'dark' : 'light';
    html.setAttribute('data-bs-theme', next);
    localStorage.setItem('fatturazione-theme', next);
    updateDarkModeIcon(next);
}

function updateDarkModeIcon(theme) {
    const icon = document.getElementById('darkModeIcon');
    if (!icon) return;
    icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
}

(function initDarkMode() {
    const saved = localStorage.getItem('fatturazione-theme') || 'light';
    updateDarkModeIcon(saved);
})();

/* ============================================================
   TOAST NOTIFICATIONS
============================================================ */
function showToast(message, type) {
    type = type || 'success';
    const iconMap = {
        success: 'bi-check-circle-fill text-success',
        danger:  'bi-x-circle-fill text-danger',
        warning: 'bi-exclamation-triangle-fill text-warning',
        info:    'bi-info-circle-fill text-info',
    };
    const icon = iconMap[type] || iconMap.success;

    const el = document.createElement('div');
    el.className = 'toast align-items-center border-0 shadow';
    el.setAttribute('role', 'alert');
    el.setAttribute('aria-live', 'polite');
    el.innerHTML = `
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2">
                <i class="bi ${icon} fs-5"></i>
                <span>${message}</span>
            </div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>`;
    document.getElementById('toast-container').appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 4000 });
    t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}

// Mostra flash messages PHP
if (typeof window._flashMessages !== 'undefined') {
    window._flashMessages.forEach(function(m) {
        showToast(m.message, m.type);
    });
}

/* ============================================================
   FORM LOADING STATE
   Disabilita il bottone submit durante l'invio per prevenire
   doppi click. Per form con target="_blank" si riabilita dopo 2s.
============================================================ */
document.addEventListener('submit', function(e) {
    const form = e.target;
    if ('noLoading' in form.dataset) return;
    const btn = form.querySelector('button[type="submit"]:not([disabled])');
    if (!btn) return;
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>'
                  + btn.innerText.trim();
    if (form.target === '_blank') {
        setTimeout(function() {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }, 2200);
    }
}, true);
</script>

<?php if (!empty($extra_scripts)) echo $extra_scripts; ?>

</body>
</html>
