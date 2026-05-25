<?php
/**
 * Middleware multi-tenancy SaaS — fatturapp
 *
 * Da includere dopo auth.php in tutte le pagine business.
 * Legge i dati del tenant dalla sessione (popolata al login).
 *
 * Funzioni esposte:
 *   getTenantId()         → int
 *   getTenantPlan()       → array
 *   isSubActive()         → bool
 *   isSaasAdmin()         → bool
 *   getTrialDaysLeft()    → int
 *   canCreate(string, mysqli) → bool
 *   requireActiveSub()    → void  (redirect se scaduto)
 */

/** Restituisce il tenant_id dalla sessione. */
function getTenantId(): int {
    return (int)($_SESSION['tenant_id'] ?? 0);
}

/**
 * Restituisce i dati del piano corrente dalla sessione.
 * @return array{name:string, id:int, status:string, trial_ends_at:string|null,
 *               max_fatture_mese:int|null, max_clienti:int|null}
 */
function getTenantPlan(): array {
    return [
        'name'            => $_SESSION['plan_name']        ?? 'free',
        'id'              => (int)($_SESSION['plan_id']    ?? 1),
        'status'          => $_SESSION['sub_status']       ?? 'expired',
        'trial_ends_at'   => $_SESSION['trial_ends_at']    ?? null,
        'max_fatture_mese'=> $_SESSION['max_fatture_mese'] ?? 3,    // null = illimitato
        'max_clienti'     => $_SESSION['max_clienti']      ?? 2,
    ];
}

/**
 * Verifica se l'abbonamento è attivo (trial valido o piano pagato).
 */
function isSubActive(): bool {
    $status = $_SESSION['sub_status'] ?? 'expired';
    if ($status === 'active') return true;
    if ($status === 'trial') {
        $ends = $_SESSION['trial_ends_at'] ?? null;
        return $ends !== null && strtotime($ends) > time();
    }
    return false;
}

/** Vero se l'utente è l'amministratore della piattaforma SaaS. */
function isSaasAdmin(): bool {
    return ($_SESSION['is_saas_admin'] ?? false) === true;
}

/** Giorni rimanenti nel trial (0 se non in trial). */
function getTrialDaysLeft(): int {
    if (($_SESSION['sub_status'] ?? '') !== 'trial') return 0;
    $ends = $_SESSION['trial_ends_at'] ?? null;
    if (!$ends) return 0;
    return max(0, (int)ceil((strtotime($ends) - time()) / 86400));
}

/**
 * Controlla se il tenant può creare una nuova risorsa in base ai limiti del piano.
 *
 * @param string $resource  'fattura' | 'cliente' | 'ore'
 * @param mysqli $conn      Connessione DB aperta
 */
function canCreate(string $resource, mysqli $conn): bool {
    if (isSaasAdmin()) return true;
    if (!isSubActive()) return false;

    $plan = getTenantPlan();
    $tid  = getTenantId();

    if ($resource === 'fattura') {
        $max = $plan['max_fatture_mese'];
        if ($max === null) return true;
        $mese = (int)date('n');
        $anno = (int)date('Y');
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM tb_fatture
             WHERE tenant_id = ? AND MONTH(data_creazione) = ? AND YEAR(data_creazione) = ?"
        );
        $stmt->bind_param('iii', $tid, $mese, $anno);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $count < $max;
    }

    if ($resource === 'cliente') {
        $max = $plan['max_clienti'];
        if ($max === null) return true;
        $stmt = $conn->prepare("SELECT COUNT(*) FROM tb_clienti WHERE tenant_id = ?");
        $stmt->bind_param('i', $tid);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $count < $max;
    }

    return true; // 'ore' e altre risorse non hanno limiti
}

/**
 * Reindirizza alla pagina di billing se l'abbonamento è scaduto/cancellato.
 * Chiamare nelle pagine di scrittura (crea fattura, traccia ore, ecc.).
 * Il SaaS admin bypassa il controllo.
 */
function requireActiveSub(): void {
    if (isSaasAdmin()) return;
    if (!isSubActive()) {
        $status = urlencode($_SESSION['sub_status'] ?? 'expired');
        // Calcola il path relativo corretto in base alla directory del file chiamante
        $depth = substr_count(str_replace($_SERVER['DOCUMENT_ROOT'], '', $_SERVER['SCRIPT_FILENAME']), '/') - 1;
        $base  = str_repeat('../', max(0, $depth - 1));
        header("Location: {$base}saas/billing.php?expired=1&reason={$status}");
        exit;
    }
}

/**
 * Ricarica i dati del piano nella sessione dal DB.
 * Da chiamare dopo un upgrade/downgrade di piano.
 */
function refreshTenantSession(mysqli $conn): void {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!$uid) return;

    $stmt = $conn->prepare(
        "SELECT u.tenant_id,
                t.status AS tenant_status,
                s.plan_id, s.status AS sub_status, s.trial_ends_at,
                p.name AS plan_name, p.max_fatture_mese, p.max_clienti
         FROM tb_utenti u
         LEFT JOIN saas_tenants t      ON t.id       = u.tenant_id
         LEFT JOIN saas_subscriptions s ON s.tenant_id = t.id
         LEFT JOIN saas_plans p        ON p.id       = s.plan_id
         WHERE u.id_utente = ? LIMIT 1"
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return;

    $_SESSION['tenant_id']        = (int)$row['tenant_id'];
    $_SESSION['plan_id']          = (int)$row['plan_id'];
    $_SESSION['plan_name']        = $row['plan_name']        ?? 'free';
    $_SESSION['sub_status']       = $row['sub_status']       ?? 'expired';
    $_SESSION['trial_ends_at']    = $row['trial_ends_at'];
    $_SESSION['max_fatture_mese'] = $row['max_fatture_mese']; // null = unlimited
    $_SESSION['max_clienti']      = $row['max_clienti'];
    $_SESSION['is_saas_admin']    = ((int)$row['tenant_id'] === 1 && ($_SESSION['ruolo'] ?? '') === 'admin');
}
