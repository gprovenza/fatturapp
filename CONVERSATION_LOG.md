# CONVERSATION_LOG.md — Log modifiche e piani futuri

Cronologia delle sessioni di lavoro con Claude. Ogni sessione documenta cosa è stato fatto, perché, e cosa resta aperto.

---

## Sessione 2026-05-11 — Audit sicurezza + bugfix massivo

### Analisi iniziale
Analisi completa di tutti i file PHP del progetto. Identificati 11 bug suddivisi per priorità.

### Bug risolti

#### Sicurezza (priorità alta)

| File | Problema | Fix applicato |
|------|----------|---------------|
| `aggiorna_pagamento.php` | CSRF token inviato da JS ma `csrf_verify()` mai chiamato | Aggiunto `csrf_verify()` |
| `aggiorna_pagamento.php` | `session_start()` ridondante prima di `require_once 'auth.php'` | Rimosso |
| `gestione_utenti.php` | Nessuna protezione CSRF su form add/edit/delete/reset-password | Rewrite completo |
| `gestione_utenti.php` | Query non preparate (check username duplicato + `$id` diretto) | Rewrite completo con prepared statements |
| `traccia_ore.php` | `DELETE FROM tb_ore_lavoro WHERE id_ore = ?` senza `AND user_id = ?` — qualsiasi utente poteva cancellare ore altrui | Aggiunto `AND user_id = ?` |
| `download_multipli.php` | Form in `visualizza_fatture.php` include CSRF ma il file non chiama `csrf_verify()` | Aggiunto `csrf_verify()` |
| `index.php` | `session_start()` ridondante | Rimosso |
| `cambia_password.php` | `session_start()` ridondante + `require_once` ridondanti già in `auth.php` | Rimossi |

#### Funzionale (priorità media)

| File | Problema | Fix applicato |
|------|----------|---------------|
| `statistiche_ore.php` | Link a `export_statistiche_pdf.php` che non esisteva | Creato il file |
| `upload_fattura.php` | Pre-selezione mese a Gennaio: `date('n') - 2` = -1 → nessun mese selezionato | `((int)date('n') - 2 + 12) % 12` |
| `upload_fattura.php` | MIME check troppo restrittivo per PDF (mancava `octet-stream`) | Reso più permissivo |
| `upload_fattura.php` | Percorso standalone: nessun `mysqli_begin_transaction`, nessun rollback in caso di errore | Aggiunto transaction + rollback + pulizia file |
| `genera_fattura_form.php` | Pre-selezione mese a Gennaio selezionava Gennaio invece di Dicembre | `((int)date('n') - 2 + 12) % 12` |
| `download_pdf.php` | `mysqli_close($conn)` dopo `die()` → codice irraggiungibile; connessione mai chiusa nei path 1-3 | `mysqli_close()` spostato prima di `die()` |

#### Qualità / UI (priorità bassa — risolto parzialmente)

| File | Problema | Stato |
|------|----------|-------|
| `gestione_utenti.php` | UI completamente diversa dal resto (no sidebar, Bootstrap 5.3.0, no flash messages) | **Risolto** con rewrite completo |

### File nuovi creati

| File | Descrizione |
|------|-------------|
| `export_statistiche_pdf.php` | Export PDF statistiche annuali (fatturato lordo/netto per mese e per progetto) usando FPDF |

---

## Sessione 2026-05-11 — Debug upload fattura elettronica (500 error)

### Problema
Upload di fattura elettronica standalone (senza pro-forma preesistente) restituiva HTTP 500.

### Indagini
- `DOC7-2026.pdf` salvato su disco (`/var/www/html/fatturazione/fatture_elettroniche/`) ma nessun record in DB → conferma che l'errore avviene dopo il salvataggio file, prima dell'INSERT
- Confronto con DOC5-2026 e DOC6-2026 (uploadati con codice vecchio e riusciti) → il bug era stato introdotto dalla sessione precedente
- Verificato schema DB locale (importato dump del server): struttura `tb_fatture_elettroniche` corretta
- PHP server: versione 8.5.4 (molto strict su by-reference)

### Bug trovato e risolto

**Causa principale:** `MARCA_BOLLO` (costante PHP) passata direttamente a `mysqli_stmt_bind_param()` nel percorso standalone di `upload_fattura.php`. PHP 8.4+ genera Fatal Error quando una costante viene passata a un parametro by-reference.

```php
// PRIMA (bug introdotto nella sessione precedente)
mysqli_stmt_bind_param($stmt, '...d...', ..., MARCA_BOLLO, ...);

// DOPO
$marca_bollo = MARCA_BOLLO;
mysqli_stmt_bind_param($stmt, '...d...', ..., $marca_bollo, ...);
```

**Fix secondari applicati:**
- `finfo_open()` può restituire `false` → aggiunta null guard: `$mime_xml = $finfo2 ? finfo_file(...) : '';`
- `catch (RuntimeException)` non cattura `TypeError` o `Error` → cambiato in `catch (\Throwable)`

### Stato al termine della sessione
I fix erano stati applicati ai file locali. Non è stato verificato se i file erano stati effettivamente deployati su `/var/www/html/fatturazione/`. Erano state aggiunte due righe di debug (`ini_set('display_errors', '1'); error_reporting(E_ALL);`) in cima a `upload_fattura.php` da rimuovere dopo il test.

> **TODO per la prossima sessione:** Verificare se il debug è ancora presente in `upload_fattura.php` (righe 1-3) e rimuoverlo dopo aver confermato che l'upload funziona.

---

## Sessione 2026-05-12 — Fix statistiche lordo/netto + miglioramenti UI

### Bug risolto: lordo e netto divergenti tra dashboard e statistiche

**Causa:** La query mensile in `statistiche_ore.php` e `export_statistiche_pdf.php` usava un LEFT JOIN tra `tb_fatture` (1) e `tb_fatture_dettaglio` (N) per ottenere sia il fatturato che le ore in un'unica query. Per ogni fattura con N righe di dettaglio, `SUM(totale_fattura)` veniva contato N volte, gonfiando il lordo.

**File corretti:** `statistiche_ore.php`, `export_statistiche_pdf.php`

**Fix:** Sostituita la query unica con due query separate:
1. `SUM(totale_fattura)` direttamente da `tb_fatture` (nessun JOIN)
2. `SUM(ore_erogate)` da `tb_fatture_dettaglio` JOIN `tb_fatture`

### Miglioramento UI: link Dashboard in sidebar

**File:** `includes/sidebar.php`

**Aggiunto** link "Dashboard" con icona `house-door` in cima alla sidebar (sopra tutte le sezioni). Permetteva già la navigazione a tutte le pagine ma mancava il ritorno alla homepage `index.php`.

---

## Sessione 2026-05-12 — Miglioramenti UI/UX (skill ui-ux-pro-max)

Design system applicato: *Data-Dense Dashboard* — navy professionale, font tecnico, massima leggibilità dati.

### Modifiche applicate

| File | Modifica |
|------|----------|
| `includes/header.php` | Font **Inter** da Google Fonts (sostituisce system-ui) |
| `includes/header.php` | Palette primaria: indigo `#6366f1` → blu navy `#2563eb` (più professionale per fatturazione/finance) |
| `includes/header.php` | Brand icon sidebar: gradiente `#1e3a5f → #2563eb` |
| `includes/header.php` | `btn-primary`: gradiente `#2563eb → #1d4ed8` |
| `includes/header.php` | Form focus ring: `rgba(37,99,235,.18)` + `border #2563eb` |
| `includes/header.php` | `font-variant-numeric: tabular-nums` su tutte le tabelle (colonne importi/ore allineate) |
| `includes/header.php` | Hover riga tabella più visibile: `rgba(37,99,235,0.06)` light / `rgba(96,165,250,0.09)` dark |
| `includes/header.php` | Focus ring `2px solid #2563eb` con `outline-offset: 2px` per accessibilità |
| `index.php` | KPI card 1 (Fatturato): `#1e3a5f → #1d4ed8` (deep navy) |
| `index.php` | KPI card 2 (Fatture): `#065f46 → #059669` (emerald) |
| `index.php` | KPI card 3 (Ore): `#92400e → #d97706` (amber professionale) |
| `index.php` | KPI card 4 (Clienti): `#1e293b → #334155` (charcoal slate) |
| `includes/sidebar.php` | Separatore visivo (border-bottom) sotto il link Dashboard |
| `includes/footer.php` | Loading state universale su tutti i form: disabilita il bottone submit + mostra spinner durante l'invio |

---

---

## Sessione 2026-05-12 — Paginazione tabelle + script permessi produzione

### Paginazione server-side

Implementata paginazione (10 righe/pagina) con ellipsis su tutte le tabelle lunghe rimaste.

| File | Tabella | Note |
|------|---------|------|
| `visualizza_fatture.php` | Archivio Fatture | Paginazione su entrambi i rami (ricerca + no ricerca). Badge contatore aggiornato a `$total_records`. Bottone "Seleziona tutto" → "Seleziona pagina" |
| `upload_fattura.php` | Archivio Fatture Elettroniche | Parametro `archivio_page` (separato da `page`). Link paginazione con `#archivio` anchor per mantenere lo scroll |

**Pattern applicato:** COUNT + LIMIT/OFFSET in PHP; nav Bootstrap con ellipsis intelligente (mostra sempre prima/ultima pagina + ±2 intorno alla corrente).

### Rimozione debug lines

Rimosse da `upload_fattura.php` le righe `ini_set('display_errors', '1')` e `error_reporting(E_ALL)` aggiunte temporaneamente nella sessione precedente.

### Script permessi produzione

Creato `fix_permissions.sh` nella root del progetto. Va eseguito come root sul server dopo ogni deploy:

```bash
sudo bash /var/www/html/fatturazione/fix_permissions.sh
```

| Percorso | Owner | Permessi |
|----------|-------|----------|
| Tutti i file | `root:www-data` | `640` |
| Tutte le directory | `root:www-data` | `750` |
| `fatture_elettroniche/`, `pdf/` | `www-data:www-data` | `770` |
| `.env`, `config.php` | `root:www-data` | `600` |
| `dump/` | `root:www-data` | `750` |

Le cartelle di upload vengono create automaticamente se mancanti.

---

## Bug aperti (non ancora risolti)

| File | Bug | Priorità |
|------|-----|----------|
| `gestione_utenti.php` | Query non preparata per check username duplicato (`mysqli_real_escape_string` invece di prepared statement) | BASSA |
| `genera_fattura.php` | Race condition nel calcolo progressivo fattura (mitigata da UNIQUE KEY ma errore risultante è generico) | BASSA |

---

## Idee e miglioramenti futuri

Funzionalità non ancora implementate ma discusse o identificate come utili:

| Idea | Descrizione | Priorità stimata |
|------|-------------|-----------------|
| Deploy automatico | Script o Makefile per sincronizzare `/home/gprovenzano/Cloud/Work/VScode/fatturazione/` con `/var/www/html/fatturazione/` evitando la copia manuale | MEDIA |
| Backup automatico DB | Script cron per dump periodico del DB su `dump/` con data nel nome file | BASSA |
| Vista commercialista dedicata | Interfaccia semplificata per il ruolo commercialista (al momento vede le stesse pagine di tutti) | BASSA |
| Notifica pagamento | Email automatica quando una fattura viene segnata come pagata | BASSA |
| Export Excel statistiche | Export XLSX delle statistiche annuali (attualmente solo CSV ore e PDF statistiche) | BASSA |
