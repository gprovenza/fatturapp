# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## Progetto

**Fatturazione** è un gestionale PHP per la gestione di una partita IVA forfettaria. L'utente traccia ore lavorate per diversi clienti/progetti, genera fatture pro-forma in PDF, carica le fatture elettroniche ricevute dal SdI e tiene lo storico con stato pagamento. Il commercialista ha accesso in sola lettura per la dichiarazione dei redditi.

**Direzione attuale:** migrazione da tool personale a **micro-SaaS** (€5-8/mese). Le funzionalità da aggiungere sono multi-tenancy per `user_id`, registrazione/login autonoma, piani Free/Pro, integrazione pagamento (PayPal / Stripe / LemonSqueezy), dashboard admin (MRR, utenti attivi), trial 30 giorni senza carta, e conformità GDPR (dati in EU, export su richiesta). I vincoli sono: nessun framework pesante, la struttura delle tabelle business esistenti non va mai modificata (solo aggiunta), deploy via Docker Compose su VPS.

Documentazione di dettaglio:
- **[AGENTS.md](AGENTS.md)** — architettura completa, flussi, convenzioni, note operative
- **[DB_SCHEMA.md](DB_SCHEMA.md)** — schema tabelle, relazioni ER, query ricorrenti

---

## Stack

| Componente | Tecnologia |
|------------|-----------|
| Backend | PHP 8.4+ (server production: PHP 8.5.4) |
| Database | MariaDB 10.11 / MySQL — DB `fatturazione` |
| Frontend | Bootstrap 5.3.3, Bootstrap Icons, Chart.js |
| PDF | FPDF (`fpdf/`) |
| Server | Apache, VPS con Docker + Nginx Proxy Manager |
| Dev locale | `/home/gprovenzano/Cloud/Work/VScode/Progetti/fatturazione/` |
| Server prod | `/var/www/html/fatturazione/` — host `dev.local`, utente `root` |

---

## Ambiente locale e deploy

**Configurazione DB locale:** credenziali in `.env` (non in repository) o in `config.php` come fallback:
```
DB_SERVER=localhost  DB_USERNAME=gprovenzano  DB_PASSWORD=smsdante  DB_NAME=fatturazione
```
Il DB locale accessibile come `root` / `Smsdante1!` per operazioni amministrative (es. creazione tabelle migration).

**Deploy su server:** copiare i file modificati in `/var/www/html/fatturazione/`, poi eseguire:
```bash
sudo bash /var/www/html/fatturazione/fix_permissions.sh
```
`fix_permissions.sh` imposta owner/permessi corretti (`root:www-data`, file `640`, dir `750`, cartelle upload `www-data:www-data 770`).

**Dump DB:** `dump/fatturazione.sql` — può essere non aggiornato; usarlo per import locale, non come fonte di verità assoluta.

---

## Architettura

### Entry point e include chain

Ogni pagina protetta parte con:
```php
require_once 'auth.php';       // carica config, functions, csrf, alerts; fa session_start + require_auth()
require_once 'db.php';         // funzione getDBConnection() → restituisce mysqli
```
`auth_admin.php` e `auth_commercialista.php` estendono `auth.php` con check sul ruolo.

**Non aggiungere mai** un secondo `session_start()` — `auth.php` lo gestisce già con il guard `PHP_SESSION_NONE`.

### Struttura pagine (layout condiviso)

```php
$page_title   = 'Titolo';
$current_page = 'nomefile.php';  // evidenzia il link attivo nella sidebar
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
// contenuto...
require_once 'includes/footer.php';
```

### Ruoli

| Ruolo | Accesso |
|-------|---------|
| `admin` | Tutto, inclusa gestione utenti e impostazioni |
| `user` | Operativo completo (ore, fatture, upload, statistiche) |
| `commercialista` | Sola lettura: visualizza fatture e statistiche |

Protezione nelle pagine: `require_role(['admin', 'user'])` da `includes/functions.php`.

### Ciclo vita fattura

```
tb_ore_lavoro (traccia_ore.php)
    → tb_fatture + tb_fatture_dettaglio + PDF in pdf/  (genera_fattura_form → genera_fattura.php)
    → tb_fatture_elettroniche (upload_fattura.php — PDF obbligatorio, XML opzionale)
    → aggiorna_pagamento.php (AJAX, segna pagata)
```
Una volta che esiste un record in `tb_fatture_elettroniche.numero_proforma`, la pro-forma **non è più editabile** (logica applicativa, non vincolo DB).

---

## Convenzioni critiche

### DB: PHP 8.4+ e bind_param

Passare sempre le costanti PHP a una variabile intermedia **prima** di `bind_param()`:
```php
$marca_bollo = MARCA_BOLLO;
mysqli_stmt_bind_param($stmt, 'd', $marca_bollo);  // NON passare MARCA_BOLLO direttamente
```
PHP 8.4+ tratta i parametri di `bind_param()` by-reference — le costanti non sono variabili e causano Fatal Error.

### JOIN 1:N e aggregazioni

Un JOIN tra `tb_fatture` (1) e `tb_fatture_dettaglio` (N) moltiplica `SUM(totale_fattura)` per ogni riga di dettaglio. Usare **sempre due query separate**:
```sql
-- Fatturato: query su tb_fatture senza JOIN
SELECT mese, SUM(totale_fattura) FROM tb_fatture WHERE anno = ? GROUP BY mese;
-- Ore: JOIN solo per aggregare ore_erogate
SELECT f.mese, SUM(fd.ore_erogate) FROM tb_fatture_dettaglio fd JOIN tb_fatture f ... GROUP BY f.mese;
```

### CSRF

Ogni form POST: `<?= csrf_field() ?>` nel form, `csrf_verify()` nell'handler PHP.  
Richieste AJAX: includere `csrf_token` come campo POST, chiamare `csrf_verify()` lato server.

### Output HTML

```php
e($var)              // htmlspecialchars ENT_QUOTES UTF-8
formatCurrency($n)   // "€ 1.234,56"
formatDate($date)    // "dd/mm/YYYY" da formato DB
getNomeMese($n)      // "Gennaio"…"Dicembre"
```

### Flash messages

```php
set_flash('Messaggio', 'success');  // 'danger' | 'warning' | 'info'
header('Location: pagina.php'); exit;
// includes/alerts.php legge e mostra automaticamente
```

### Sicurezza query

- **Sempre** prepared statements + `mysqli_stmt_bind_param()` — mai interpolazione diretta
- `DELETE` su `tb_ore_lavoro` deve includere `AND user_id = ?` per prevenire cancellazioni cross-user

---

## Tabelle principali (sintesi)

| Tabella | Scopo |
|---------|-------|
| `tb_utenti` | Login, ruoli (`admin`/`user`/`commercialista`) |
| `tb_anagrafiche` | Dati P.IVA intestatario (una riga); contiene `tasse_percentuale` |
| `tb_clienti` | Aziende fatturate |
| `tb_progetti` | Attività per cliente, con `paga_oraria` (€/h) |
| `tb_ore_lavoro` | Registrazioni giornaliere ore (`user_id` + `progetto_id` + `data_lavoro`) |
| `tb_fatture` | Pro-forma generate; `numero_fattura` UNIQUE |
| `tb_fatture_dettaglio` | Righe fattura; `costo_orario` storicizzato (non dipende da `tb_progetti`) |
| `tb_fatture_elettroniche` | File PDF/XML caricati; `numero_proforma → tb_fatture.id_fattura` (nullable) |
| `tb_impostazioni` | KV store: `prefisso_fattura`, `progressivo_fattura`, `anno_progressivo` |

Schema completo con tipi, FK e query ricorrenti: **[DB_SCHEMA.md](DB_SCHEMA.md)**.

---

## Bug aperti noti

| File | Problema | Priorità |
|------|----------|----------|
| `gestione_utenti.php` | Check username duplicato usa `mysqli_real_escape_string` invece di prepared statement | BASSA |
| `genera_fattura.php` | Race condition su progressivo fattura (mitigata da UNIQUE KEY, ma errore risultante è generico) | BASSA |

---

## Roadmap SaaS (da implementare)

Le nuove tabelle da aggiungere per la multi-tenancy e gli abbonamenti devono affiancare quelle esistenti **senza toccarle**. Schema da definire per: `saas_users` (registrazione autonoma), `saas_subscriptions` (piani/trial), `saas_plans`, integrazione webhook pagamento. Le tabelle business esistenti vanno collegate tramite `tenant_id` o `user_id` addizionale, con row-level security a livello applicativo.

Ogni funzionalità SaaS deve rispettare GDPR: dati in EU, endpoint export dati utente, retention policy.
