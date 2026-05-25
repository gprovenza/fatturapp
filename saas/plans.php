<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/tenant.php';

$plan      = getTenantPlan();
$trialDays = getTrialDaysLeft();
$isPro     = (strtolower($plan['name'] ?? '') === 'pro' && $plan['status'] === 'active');

$page_title  = 'Piani e Prezzi';
$current_page = 'saas/plans.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-content">
    <h2 class="mb-2 text-center"><i class="bi bi-gem me-2 text-warning"></i>Piani e Prezzi</h2>
    <p class="text-center text-muted mb-5">Scegli il piano più adatto alla tua attività</p>

    <?php if ($plan['status'] === 'trial'): ?>
    <div class="alert alert-info text-center mb-4">
        <i class="bi bi-hourglass-split me-2"></i>
        Stai utilizzando la prova gratuita Pro — <strong><?= $trialDays ?> giorni rimasti</strong>.
        Attiva il piano Pro per non perdere l'accesso.
    </div>
    <?php elseif (!$isPro): ?>
    <div class="alert alert-warning text-center mb-4">
        <i class="bi bi-exclamation-triangle me-2"></i>
        Il tuo piano <strong>Free</strong> ha alcune limitazioni. Passa a Pro per sbloccare tutto.
    </div>
    <?php endif; ?>

    <div class="row justify-content-center g-4 mb-5">

        <!-- Piano FREE -->
        <div class="col-md-5">
            <div class="card h-100 border-2 <?= !$isPro ? 'border-secondary' : 'border-light' ?> shadow-sm">
                <?php if (!$isPro && $plan['status'] !== 'trial'): ?>
                <div class="card-header bg-secondary text-white text-center fw-bold">Piano Attuale</div>
                <?php endif; ?>
                <div class="card-body p-4">
                    <h3 class="fw-bold mb-1">Free</h3>
                    <p class="text-muted mb-3">Per iniziare</p>
                    <div class="display-5 fw-bold mb-4">€ 0 <small class="fs-6 text-muted fw-normal">/mese</small></div>
                    <ul class="list-unstyled mb-4">
                        <li class="py-2 border-bottom"><i class="bi bi-check-circle text-success me-2"></i>3 fatture pro-forma al mese</li>
                        <li class="py-2 border-bottom"><i class="bi bi-check-circle text-success me-2"></i>2 clienti</li>
                        <li class="py-2 border-bottom"><i class="bi bi-check-circle text-success me-2"></i>Registrazione ore illimitata</li>
                        <li class="py-2 border-bottom"><i class="bi bi-check-circle text-success me-2"></i>Export CSV ore</li>
                        <li class="py-2 text-muted"><i class="bi bi-x-circle text-danger me-2"></i>Upload fatture elettroniche</li>
                        <li class="py-2 text-muted"><i class="bi bi-x-circle text-danger me-2"></i>Statistiche avanzate</li>
                    </ul>
                    <?php if (!$isPro && $plan['status'] !== 'trial'): ?>
                    <button class="btn btn-secondary w-100" disabled>Piano Corrente</button>
                    <?php else: ?>
                    <button class="btn btn-outline-secondary w-100" disabled>Gratis</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Piano PRO -->
        <div class="col-md-5">
            <div class="card h-100 border-3 border-success shadow position-relative">
                <div class="position-absolute top-0 start-50 translate-middle">
                    <span class="badge bg-success px-3 py-2 fs-6">Consigliato</span>
                </div>
                <?php if ($isPro): ?>
                <div class="card-header bg-success text-white text-center fw-bold">Piano Attuale</div>
                <?php elseif ($plan['status'] === 'trial'): ?>
                <div class="card-header bg-info text-dark text-center fw-bold">
                    In prova — <?= $trialDays ?> giorni rimasti
                </div>
                <?php endif; ?>
                <div class="card-body p-4">
                    <h3 class="fw-bold mb-1 text-success">Pro</h3>
                    <p class="text-muted mb-3">Per professionisti</p>
                    <div class="display-5 fw-bold mb-4 text-success">€ 7 <small class="fs-6 text-muted fw-normal">/mese</small></div>
                    <ul class="list-unstyled mb-4">
                        <li class="py-2 border-bottom"><i class="bi bi-check-circle text-success me-2"></i><strong>Fatture illimitate</strong></li>
                        <li class="py-2 border-bottom"><i class="bi bi-check-circle text-success me-2"></i><strong>Clienti illimitati</strong></li>
                        <li class="py-2 border-bottom"><i class="bi bi-check-circle text-success me-2"></i>Registrazione ore illimitata</li>
                        <li class="py-2 border-bottom"><i class="bi bi-check-circle text-success me-2"></i>Export CSV + PDF statistiche</li>
                        <li class="py-2 border-bottom"><i class="bi bi-check-circle text-success me-2"></i>Upload fatture elettroniche (PDF + XML)</li>
                        <li class="py-2 border-bottom"><i class="bi bi-check-circle text-success me-2"></i>Statistiche avanzate per anno</li>
                        <li class="py-2"><i class="bi bi-check-circle text-success me-2"></i>Accesso commercialista</li>
                    </ul>

                    <?php if ($isPro): ?>
                    <button class="btn btn-success w-100" disabled>
                        <i class="bi bi-check-circle me-1"></i> Piano Attivo
                    </button>
                    <?php else: ?>
                    <a href="paypal/create-subscription.php" class="btn btn-success w-100 btn-lg">
                        <i class="bi bi-paypal me-1"></i> Abbonati con PayPal — € 7/mese
                    </a>
                    <p class="text-center text-muted mt-2 mb-0">
                        <small><i class="bi bi-shield-check me-1"></i>Pagamento sicuro · Cancellabile in qualsiasi momento</small>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- FAQ -->
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <h4 class="mb-3 fw-bold">Domande frequenti</h4>
            <div class="accordion" id="faqAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                            Come funziona il trial gratuito?
                        </button>
                    </h2>
                    <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Al momento della registrazione ottieni 30 giorni di prova gratuita del piano Pro, senza carta di credito richiesta. Al termine del trial, se non attivi il piano, passi automaticamente al piano Free.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                            Posso cancellare in qualsiasi momento?
                        </button>
                    </h2>
                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Sì. Puoi cancellare l'abbonamento in qualsiasi momento dalla pagina Abbonamento. Mantieni l'accesso Pro fino alla fine del periodo già pagato.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                            I miei dati sono al sicuro?
                        </button>
                    </h2>
                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            I tuoi dati sono conservati su server in Unione Europea. Ogni utente vede esclusivamente i propri dati. Puoi richiedere l'esportazione o la cancellazione dei dati in qualsiasi momento.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
