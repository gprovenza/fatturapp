<?php
/**
 * Autenticazione commercialista: qualsiasi ruolo autenticato.
 * Mantiene le funzioni helper originali.
 */
require_once __DIR__ . '/auth.php';

function isCommercialista(): bool {
    return ($_SESSION['ruolo'] ?? '') === 'commercialista';
}

function canEdit(): bool {
    return in_array($_SESSION['ruolo'] ?? '', ['admin', 'user'], true);
}
