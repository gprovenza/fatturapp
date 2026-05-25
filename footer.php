<style>
    html, body {
        height: 100%;
        margin: 0;
        padding: 0;
    }
    body {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
    .main-content {
        flex: 1 0 auto;
    }
    footer {
        flex-shrink: 0;
        width: 100%;
        margin-top: auto !important;
    }
</style>

<footer class="bg-dark text-white py-3">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-4 text-start">
                <span class="text-muted">
                    <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?>
                    <?php if (isset($_SESSION['ruolo'])): ?>
                        <span class="badge bg-<?= $_SESSION['ruolo'] == 'admin' ? 'danger' : 'secondary' ?> ms-1">
                            <?= ucfirst($_SESSION['ruolo']) ?>
                        </span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="col-md-4 text-center">
                <small>© <?= date('Y') ?> Sistema Fatturazione</small>
            </div>
            <div class="col-md-4 text-end">
                <small class="text-muted">
                    <i class="bi bi-calendar"></i> <?= date('d/m/Y H:i') ?>
                </small>
            </div>
        </div>
    </div>
</footer>
