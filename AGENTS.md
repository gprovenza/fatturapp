# AGENTS.md — Documentazione progetto Fatturazione

Guida completa all'applicativo per Claude e altri agenti AI che lavorano su questo codebase.

---

## Panoramica

Applicativo PHP per la gestione di una **partita IVA forfettaria** di un lavoratore autonomo. Permette di:

- Registrare giornalmente le ore lavorate per cliente e progetto
- Generare fatture pro-forma in PDF
- Caricare le fatture elettroniche (PDF + XML) ricevute dal sistema SDI
- Tenere uno storico delle fatture con stato pagamento
- Visualizzare statistiche e report annuali
- Dare accesso al commercialista per la dichiarazione dei redditi

**Contesto business:**  
L'utente è un lavoratore autonomo che fattura a ore a diverse aziende. Ogni azienda ha più progetti, ognuno con tariffa oraria propria. Le fatture sono in regime forfettario (nessuna IVA, marca da bollo da €2,00 obbligatoria, aliquota tasse 35% sul lordo).

---

## Stack tecnologico

| Componente | Tecnologia |
|------------|-----------|
| Backend | PHP 8.4+ (server: PHP 8.5.4) |
| Database | MariaDB 10.11 / MySQL |
| Frontend | Bootstrap 5.3.3, Bootstrap Icons, Chart.js |
| PDF generazione | FPDF (libreria `fpdf/`) |
| Server | Apache, path `/var/www/html/fatturazione/` |
| Dev locale | `/home/gprovenzano/Cloud/Work/VScode/fatturazione/` |

> **ATTENZIONE:** I file di sviluppo locale e quelli server sono directory distinte.  
> Per applicare le modifiche al server occorre copiare i file in `/var/www/html/fatturazione/`.  
> Server: `dev.local` — utente `root` — password `smsdante`

---

## Credenziali e configurazione

Gestite tramite `config.php` (legge da `.env` se presente, altrimenti usa i default):

| Costante | Valore default | Descrizione |
|----------|---------------|-------------|
| `DB_SERVER` | `localhost` | Host MySQL |
| `DB_USERNAME` | `gprovenzano` | Utente DB locale |
| `DB_PASSWORD` | `smsdante` | Password DB locale (server: `root`/`smsdante`) |
| `DB_NAME` | `fatturazione` | Nome database |
| `MARCA_BOLLO` | `2.0` | Valore fisso marca da bollo in € |
| `TAX_PERCENTAGE` | `35.0` | Percentuale tasse forfettarie (fallback se non in DB) |
| `MAX_UPLOAD_MB` | `20` | Limite upload file |
| `SESSION_TIMEOUT` | `1800` | Timeout sessione in secondi |
| `UPLOAD_DIR` | `__DIR__/fatture_elettroniche/` | Directory upload fatture elettroniche |
| `PDF_DIR` | `__DIR__/pdf/` | Directory PDF pro-forma generati |

---

## Struttura file

### File condivisi (includes/)

| File | Scopo |
|------|-------|
| `includes/header.php` | `<head>`, Bootstrap, CSS, apertura `<body>` |
| `includes/sidebar.php` | Sidebar navigazione + topbar mobile + overlay |
| `includes/footer.php` | Chiusura layout, Chart.js, script comuni, dark mode |
| `includes/csrf.php` | `csrf_field()` e `csrf_verify()` — protezione CSRF |
| `includes/functions.php` | Helper: `formatCurrency()`, `calcolaNetto()`, `e()`, `formatDate()`, `require_role()` |
| `includes/alerts.php` | Rendering flash messages |

### File di autenticazione e configurazione

| File | Scopo |
|------|-------|
| `config.php` | Costanti globali, connessione DB, gestione .env |
| `db.php` | `getDBConnection()` — restituisce connessione mysqli |
| `auth.php` | Include config, csrf, functions, alerts — verifica sessione attiva — `set_flash()` |
| `auth_admin.php` | Restrizione solo ruolo `admin` |
| `auth_commercialista.php` | Restrizione ruoli `admin` + `commercialista` |
| `login.php` | Form login |
| `logout.php` | Distrugge sessione |

### Pagine principali

| File | Ruoli | Descrizione |
|------|-------|-------------|
| `index.php` | tutti | Dashboard: KPI anno, fatture recenti, accesso rapido |
| `traccia_ore.php` | admin, user | Registrazione ore giornaliere per progetto |
| `riepilogo_ore.php` | admin, user | Popup riepilogo ore per mese (aperto da genera_fattura_form) |
| `genera_fattura_form.php` | admin, user | Form generazione fattura pro-forma |
| `genera_fattura.php` | admin, user | Controller POST: genera PDF + salva in DB |
| `upload_fattura.php` | admin, user | Upload fattura elettronica (PDF obbligatorio, XML opzionale) |
| `visualizza_fatture.php` | tutti | Archivio fatture con filtri, download, mark-as-paid |
| `download_pdf.php` | tutti | Serve il PDF di una fattura (pro-forma o elettronica) |
| `download_multipli.php` | tutti | Download ZIP di più PDF selezionati |
| `statistiche_ore.php` | tutti | Statistiche annuali: fatturato lordo/netto, ore, grafici |
| `export_statistiche_pdf.php` | tutti | Export PDF statistiche annuali (FPDF) |
| `export_ore_excel.php` | admin, user | Export CSV ore lavorate con filtri |
| `aggiorna_pagamento.php` | admin, user | AJAX: segna fattura come pagata/non pagata |
| `cambia_password.php` | tutti | Form cambio password utente corrente |

### Gestione (solo admin/user)

| File | Ruoli | Descrizione |
|------|-------|-------------|
| `gestione_clienti.php` | admin, user | CRUD clienti |
| `gestione_progetti.php` | admin, user | CRUD progetti (con tariffa oraria per cliente) |
| `gestione_piva.php` | admin, user | Dati anagrafica P.IVA (una sola riga in DB) |
| `impostazioni.php` | admin | Prefisso fattura, progressivo |
| `gestione_utenti.php` | admin | CRUD utenti con ruoli |

---

## Sistema di autenticazione e sicurezza

### Ruoli

| Ruolo | Accesso |
|-------|---------|
| `admin` | Tutto, inclusa gestione utenti e impostazioni |
| `user` | Operativo completo (ore, fatture, upload, statistiche) |
| `commercialista` | Solo lettura: visualizza fatture e statistiche |

### CSRF

- Ogni form include `<?= csrf_field() ?>` che stampa `<input type="hidden" name="csrf_token" value="...">`
- Ogni handler POST chiama `csrf_verify()` (in `includes/csrf.php`)
- Per richieste AJAX (es. `aggiorna_pagamento.php`) il token viene inviato come campo form

### Sessioni

- `auth.php` gestisce `session_start()` con check `PHP_SESSION_NONE` — non duplicare `session_start()` nei singoli file
- Timeout: 30 minuti di inattività → redirect a `login.php?timeout=1`
- `$_SESSION['user_id']`, `$_SESSION['username']`, `$_SESSION['ruolo']`

### Flash messages

```php
set_flash('Messaggio', 'success'); // oppure 'danger', 'warning', 'info'
// poi redirect — includes/alerts.php li legge e li mostra
```

---

## Logica business principale

### Ciclo vita fattura

```
1. Traccia ore giornaliere (tb_ore_lavoro)
          ↓
2. Genera fattura pro-forma (genera_fattura_form.php → genera_fattura.php)
   - Crea record in tb_fatture + righe in tb_fatture_dettaglio
   - Genera PDF salvato in pdf/
          ↓
3. Carica fattura elettronica (upload_fattura.php)
   - PDF obbligatorio + XML opzionale
   - Può collegarsi a una pro-forma esistente (numero_proforma)
   - Oppure "standalone": crea automaticamente un record pro-forma fittizio
   - Una volta collegata la fattura elettronica, la pro-forma non è più editabile
          ↓
4. Segna come pagata (aggiorna_pagamento.php — AJAX)
```

### Numerazione fatture

- Formato: `{prefisso}{progressivo}-{anno}` (es. `DOC5-2026`)  
- Prefisso e progressivo in `tb_impostazioni` (chiavi: `prefisso_fattura`, `progressivo_fattura`, `anno_progressivo`)
- Se cambia l'anno il progressivo riparte da 1
- UNIQUE KEY su `numero_fattura` in `tb_fatture` previene duplicati in caso di race condition

### Calcolo importi fattura

```
totale_prestazioni = SUM(ore_erogate × costo_orario) per ogni riga dettaglio
marca_bollo        = 2.00 € (costante MARCA_BOLLO)
totale_fattura     = totale_prestazioni + marca_bollo
```

### Calcolo netto stimato

```
netto = lordo × (1 - tasse_percentuale / 100)
```
La `tasse_percentuale` viene letta da `tb_anagrafiche` (campo per record, default 35%).  
La costante `TAX_PERCENTAGE` in `config.php` è solo il fallback se il DB non ha il valore.

---

## Convenzioni di codice

### Query al DB

- Usare **sempre** prepared statements con `mysqli_prepare()` + `mysqli_stmt_bind_param()`
- Prima di passare una costante a `bind_param()`, assegnarla a una variabile:
  ```php
  $marca_bollo = MARCA_BOLLO; // NON passare MARCA_BOLLO direttamente
  mysqli_stmt_bind_param($stmt, 'd', $marca_bollo);
  ```
  Motivo: PHP 8.4+ tratta i parametri by-reference e le costanti non sono variabili.

- Chiudere sempre statement e connessione: `mysqli_stmt_close()`, `mysqli_close()`

### Query con JOIN 1:N e aggregazioni

Se si fa un JOIN tra `tb_fatture` (1) e `tb_fatture_dettaglio` (N) e si vuole `SUM(totale_fattura)`, usare **query separate**:

```php
// SBAGLIATO — SUM(totale_fattura) viene moltiplicato per N righe dettaglio
SELECT SUM(f.totale_fattura), SUM(fd.ore_erogate)
FROM tb_fatture f LEFT JOIN tb_fatture_dettaglio fd ON fd.id_fattura = f.id_fattura

// CORRETTO — due query distinte
SELECT mese, SUM(totale_fattura) FROM tb_fatture WHERE anno = ? GROUP BY mese
SELECT f.mese, SUM(fd.ore_erogate) FROM tb_fatture_dettaglio fd JOIN tb_fatture f ... GROUP BY f.mese
```

### Output HTML

- Usare sempre `e($var)` (alias di `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`) per output variabili
- `formatCurrency($amount)` per importi in euro
- `formatDate($date)` per date da formato DB a `d/m/Y`

### Struttura pagine

Ogni pagina che usa il layout condiviso deve impostare `$page_title` e `$current_page` prima degli include:

```php
$page_title  = 'Titolo pagina';
$current_page = 'nomefile.php';  // usato dalla sidebar per evidenziare il link attivo
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
// ... contenuto pagina ...
require_once 'includes/footer.php';
```

---

## Directory e file non-PHP rilevanti

| Path | Contenuto |
|------|-----------|
| `pdf/` | PDF pro-forma generati da `genera_fattura.php` |
| `fatture_elettroniche/` | PDF e XML caricati tramite `upload_fattura.php` |
| `fpdf/` | Libreria FPDF per generazione PDF |
| `dump/fatturazione.sql` | Ultimo dump del DB (da aggiornare periodicamente) |
| `.env` | Credenziali DB, PayPal, SMTP (NON in repository) |
| `.env.example` | Template variabili d'ambiente (in repository) |
| `CLAUDE.md` | Istruzioni brevi per Claude Code |
| `AGENTS.md` | Questo file |
| `DB_SCHEMA.md` | Schema completo del database |
| `docker-compose.yml` | Stack Docker per VPS (apache + mariadb + cron) |
| `Dockerfile` | Immagine PHP 8.4 + Apache con estensioni necessarie |

---

## Architettura SaaS (migrazione in corso)

Il progetto è in fase di trasformazione da tool personale a **micro-SaaS** (€7/mese).

### Struttura directory SaaS

```
saas/
├── register.php          Registrazione autonoma utenti
├── verify.php            Verifica email (link da email)
├── forgot-password.php   Richiesta reset password
├── reset-password.php    Cambio password via token
├── billing.php           Stato abbonamento + storico pagamenti
├── plans.php             Confronto Free/Pro + CTA upgrade
├── gdpr-export.php       Export dati JSON (GDPR Art.20)
├── .htaccess             Protezione directory
├── paypal/
│   ├── paypal-api.php           Helper REST API PayPal
│   ├── create-subscription.php  Avvio flusso abbonamento
│   ├── success.php              Ritorno da PayPal (attiva sub)
│   ├── cancel.php               Utente annulla su PayPal
│   ├── cancel-subscription.php  Cancellazione dal portale
│   └── webhook.php              Handler eventi PayPal (IPN)
└── admin/
    ├── auth.php          Guard isSaasAdmin()
    ├── dashboard.php     KPI: MRR, trial, pagamenti
    ├── tenants.php       Lista + gestione tenant
    └── setup.php         Checklist configurazione piattaforma

cron/
└── subscription-maintenance.php  Giornaliero: trial→expired, reminder, cleanup

setup/
└── create-paypal-plan.php  CLI: crea prodotto+piano PayPal, stampa PLAN_ID

includes/
├── tenant.php   Middleware multi-tenancy (getTenantId, canCreate, requireActiveSub…)
└── mailer.php   sendMail() + template email (verifica, reset, trial reminder)
```

### Tabelle SaaS (non modificare le business)

| Tabella | Scopo |
|---------|-------|
| `saas_plans` | Piani Free/Pro con limiti (max_fatture_mese, max_clienti) |
| `saas_tenants` | Un record per cliente SaaS; owner_user_id = tb_utenti.id_utente |
| `saas_subscriptions` | Abbonamento attivo: status (trial/active/expired/cancelled), paypal_subscription_id |
| `saas_payments` | Storico pagamenti PayPal (amount, provider_payment_id, paid_at) |
| `saas_tenant_settings` | KV store per-tenant: prefisso_fattura, progressivo_fattura, anno_progressivo |
| `saas_gdpr_exports` | Registro richieste export dati (GDPR Art.20) |

### Regole multi-tenancy

- **Ogni query** sulle tabelle business include `AND tenant_id = ?` (o `WHERE tenant_id = ?`)
- **Ogni INSERT** business include la colonna `tenant_id`
- `getTenantId()` legge da `$_SESSION['tenant_id']` (popolato al login)
- `canCreate('fattura'|'cliente', $conn)` controlla i limiti del piano Free
- `requireActiveSub()` redirect a `saas/billing.php` se sub scaduta
- L'admin SaaS (tenant_id=1, ruolo=admin) bypassa tutti i controlli piano

### Flusso abbonamento PayPal

```
Utente → saas/plans.php
       → saas/paypal/create-subscription.php  (crea sub PayPal → salva ID in sessione)
       → PayPal.com (approvazione utente)
       → saas/paypal/success.php  (attiva sub nel DB, refresh sessione)

Webhook (asincrono):
PayPal → saas/paypal/webhook.php
         PAYMENT.SALE.COMPLETED    → rinnova current_period_end, registra pagamento
         BILLING.SUBSCRIPTION.*    → aggiorna status abbonamento
```

### Deploy su VPS

```bash
# 1. Copia file sul server
rsync -av --exclude='.git' --exclude='.env' . root@dev.local:/var/www/html/fatturazione/

# 2. Fix permessi
sudo bash /var/www/html/fatturazione/fix_permissions.sh

# 3. Prima volta: migration DB
mysql -u root -p fatturazione < /var/www/html/fatturazione/migrations/001_saas_foundation.sql

# 4. Prima volta: crea piano PayPal
php /var/www/html/fatturazione/setup/create-paypal-plan.php

# 5. Configura cron (giornaliero alle 08:00)
echo "0 8 * * * /usr/bin/php /var/www/html/fatturazione/cron/subscription-maintenance.php >> /var/log/fatturapp-cron.log 2>&1" | crontab -

# Con Docker:
cd /opt/fatturapp && docker compose up -d
```

---

## Note operative per agenti AI

1. **Non duplicare `session_start()`** — `auth.php` lo gestisce già con il guard `PHP_SESSION_NONE`
2. **Sempre CSRF** su ogni form POST e ogni handler AJAX — `csrf_field()` nel form, `csrf_verify()` nell'handler
3. **Upload fattura elettronica standalone** — se non c'è pro-forma collegata, `upload_fattura.php` crea automaticamente un record in `tb_fatture` per mantenere la coerenza referenziale
4. **Tasse%** — leggere sempre da `tb_anagrafiche.tasse_percentuale`, non usare la costante come valore primario
5. **Il DB dump in `dump/` può essere stale** — importarlo localmente per verifiche, non come fonte di verità
6. **Colonne saas_plans**: usa `price_monthly` (non `price_eur`), `display_name` per UI
7. **Colonne saas_payments**: usa `amount` (non `amount_eur`), `provider_payment_id` (non `paypal_transaction_id`)
8. **saas_subscriptions**: nessun UNIQUE su tenant_id — usare sempre UPDATE (non INSERT ON DUPLICATE KEY)
9. **getTenantPlan()** restituisce chiavi `name` e `status` (non `plan_name`/`sub_status` che sono i nomi delle session vars grezze)
