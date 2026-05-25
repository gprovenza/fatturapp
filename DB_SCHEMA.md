# DB_SCHEMA.md — Schema Database fatturapp

Database: `fatturazione` — MariaDB 10.11 / MySQL  
Ultimo aggiornamento: 2026-05-25 (migration 001_saas_foundation)  
Charset: `utf8mb4_general_ci`

---

## Diagramma relazioni (ER)

### Tabelle business (pre-SaaS, invariate)
```
tb_anagrafiche (1) ──────────────────────────── (N) tb_fatture
tb_clienti     (1) ──────────────────────────── (N) tb_fatture
tb_fatture     (1) ──────────────────────────── (N) tb_fatture_dettaglio
tb_progetti    (1) ──────────────────────────── (N) tb_fatture_dettaglio
tb_fatture     (1) ──────────────────────────── (0..1) tb_fatture_elettroniche
tb_utenti      (1) ──────────────────────────── (N) tb_fatture_elettroniche
tb_clienti     (1) ──────────────────────────── (N) tb_progetti
tb_progetti    (1) ──────────────────────────── (N) tb_ore_lavoro
tb_utenti      (1) ──────────────────────────── (N) tb_ore_lavoro
```

### Tabelle SaaS (aggiunte da migration 001)
```
saas_tenants       (1) ──── (N) saas_subscriptions
saas_plans         (1) ──── (N) saas_subscriptions
saas_subscriptions (1) ──── (N) saas_payments
saas_tenants       (1) ──── (N) saas_tenant_settings
saas_tenants       (1) ──── (N) saas_gdpr_exports

-- tenant_id aggiunto a tutte le tabelle business:
saas_tenants (1) ──── (N) tb_utenti
saas_tenants (1) ──── (N) tb_anagrafiche
saas_tenants (1) ──── (N) tb_clienti
saas_tenants (1) ──── (N) tb_progetti
saas_tenants (1) ──── (N) tb_fatture
saas_tenants (1) ──── (N) tb_fatture_dettaglio
saas_tenants (1) ──── (N) tb_fatture_elettroniche
saas_tenants (1) ──── (N) tb_ore_lavoro
```

---

## Tabelle

### `tb_anagrafiche`

Dati dell'intestatario della P.IVA (normalmente una sola riga).

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id_anagrafica` | int(11) PK AI | — | Identificativo |
| `denominazione` | varchar(255) | NULL | Nome azienda / nome completo |
| `nome` | varchar(255) | NULL | Nome proprio |
| `cognome` | varchar(255) | NULL | Cognome |
| `indirizzo` | varchar(255) | NULL | Via e numero civico |
| `citta` | varchar(255) | NULL | Città |
| `provincia` | varchar(255) | NULL | Sigla provincia |
| `cap` | varchar(255) | NULL | CAP |
| `partita_iva` | varchar(255) | NULL | Partita IVA |
| `codice_fiscale` | varchar(255) | NULL | Codice fiscale |
| `PR` | varchar(255) | NULL | Numero di iscrizione albo / registro |
| `tasse_percentuale` | decimal(5,2) | 35.00 | Aliquota tasse forfettarie % |

**Note:** Usata anche per reperire `tasse_percentuale` nel calcolo del netto stimato. Se NULL, `config.php` usa `TAX_PERCENTAGE = 35.0` come fallback.

---

### `tb_clienti`

Aziende a cui viene fatturato.

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id_cliente` | int(11) PK AI | — | Identificativo |
| `denominazione` | varchar(255) | NULL | Ragione sociale |
| `indirizzo` | varchar(255) | NULL | Indirizzo sede |
| `citta` | varchar(255) | NULL | Città |
| `provincia` | varchar(255) | NULL | Sigla provincia |
| `cap` | varchar(255) | NULL | CAP |
| `partita_iva` | varchar(255) | NULL | P.IVA cliente |
| `codice_fiscale` | varchar(255) | NULL | Codice fiscale cliente |
| `SDI` | varchar(255) | NULL | Codice destinatario SDI per fatturazione elettronica |

---

### `tb_progetti`

Attività/progetti per ogni cliente, ognuno con tariffa oraria propria.

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id_progetto` | int(11) PK AI | — | Identificativo |
| `nome_progetto` | varchar(255) | NULL | Nome dell'attività/progetto |
| `CUP` | varchar(255) | NULL | Codice CUP (identificativo progetto PA) |
| `paga_oraria` | decimal(10,2) | — | Tariffa oraria singola (€/h) |
| `tariffa_gruppo` | decimal(10,2) | 0.00 | Tariffa per ore in gruppo (non usata attivamente) |
| `id_cliente` | int(11) FK | NULL | Riferimento a `tb_clienti.id_cliente` |

**Dati attuali:**
- Progetto 1: Orientamento Specialistico Bando GOL Sicilia — 15,00 €/h (ADECCO)
- Progetto 2: Accompagnamento al lavoro Bando GOL Sicilia — 22,00 €/h (ADECCO)
- Progetto 3: ASACOM — 15,61 €/h (COMUNE DI PALERMO)

---

### `tb_fatture`

Fatture pro-forma generate. Può essere una pro-forma reale o una "fittizia" creata automaticamente quando si carica una fattura elettronica senza pro-forma associata.

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id_fattura` | int(11) PK AI | — | Identificativo |
| `numero_fattura` | varchar(50) UNIQUE | — | Es. `DOC5-2026` |
| `anagrafica_id` | int(11) FK | — | → `tb_anagrafiche.id_anagrafica` |
| `cliente_id` | int(11) FK | — | → `tb_clienti.id_cliente` |
| `mese` | varchar(20) | — | Nome mese in italiano (es. `Maggio`) |
| `anno` | int(11) | — | Anno fattura (es. `2026`) |
| `totale_prestazioni` | decimal(10,2) | — | Importo servizi (lordo senza marca da bollo) |
| `marca_bollo` | decimal(10,2) | — | Marca da bollo (fisso 2,00 €) |
| `totale_fattura` | decimal(10,2) | — | Totale = prestazioni + marca da bollo |
| `pagata` | tinyint(1) | 0 | 0 = non pagata, 1 = pagata |
| `data_pagamento` | date | NULL | Data effettivo pagamento |
| `data_creazione` | timestamp | CURRENT_TIMESTAMP | Creazione automatica |
| `pdf_path` | varchar(255) | NULL | Path relativo del PDF pro-forma (es. `pdf/DOC5-2026.pdf`) |

**Vincoli:**
- `UNIQUE KEY numero_fattura` — previene duplicati in caso di race condition
- FK su `anagrafica_id` e `cliente_id` con constraint InnoDB
- Una volta che esiste un record corrispondente in `tb_fatture_elettroniche`, la fattura non deve essere editabile (logica applicativa, non FK)

---

### `tb_fatture_dettaglio`

Righe della fattura: un record per ogni progetto incluso nella fattura.

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id_dettaglio` | int(11) PK AI | — | Identificativo |
| `id_fattura` | int(11) FK | — | → `tb_fatture.id_fattura` (CASCADE DELETE) |
| `progetto_id` | int(11) FK | — | → `tb_progetti.id_progetto` |
| `ore_erogate` | decimal(10,2) | — | Ore fatturate per questo progetto |
| `costo_orario` | decimal(10,2) | — | Tariffa oraria al momento della fattura (storicizzata) |
| `subtotale` | decimal(10,2) | — | `ore_erogate × costo_orario` |

**Note:** `costo_orario` è storicizzato al momento della fattura — non dipende da `tb_progetti.paga_oraria` che potrebbe cambiare nel tempo.

**JOIN importante:** Una fattura con N righe in `tb_fatture_dettaglio` espande il risultato di N righe in qualsiasi JOIN. Se si vuole `SUM(totale_fattura)` e `SUM(ore_erogate)` insieme, usare **due query separate** (vedi `statistiche_ore.php`).

---

### `tb_fatture_elettroniche`

Fatture elettroniche caricate tramite `upload_fattura.php`.

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id_fattura_elettronica` | int(11) PK AI | — | Identificativo |
| `numero_fattura` | varchar(50) UNIQUE | — | Numero fattura elettronica (es. `DOC5-2026`) |
| `numero_proforma` | int(11) FK | NULL | → `tb_fatture.id_fattura` (NULL se standalone) |
| `pdf_filename` | varchar(255) | — | Nome file PDF originale |
| `pdf_path` | varchar(500) | — | Path relativo PDF (es. `fatture_elettroniche/DOC5-2026_xxx.pdf`) |
| `xml_filename` | varchar(255) | NULL | Nome file XML (opzionale) |
| `xml_path` | varchar(500) | NULL | Path relativo XML |
| `data_upload` | timestamp | CURRENT_TIMESTAMP | Data caricamento |
| `uploaded_by` | int(11) FK | — | → `tb_utenti.id_utente` |
| `note` | text | NULL | Note libere |

**Note:** Quando `numero_proforma` è NULL significa che la fattura elettronica non era associata a una pro-forma preesistente — `upload_fattura.php` crea comunque un record fittizio in `tb_fatture` per la coerenza referenziale.

---

### `tb_ore_lavoro`

Registrazioni giornaliere delle ore lavorate.

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id_ore` | int(11) PK AI | — | Identificativo |
| `data_lavoro` | date | — | Data della giornata lavorativa |
| `progetto_id` | int(11) FK | — | → `tb_progetti.id_progetto` |
| `tipo_ore` | enum('singolo','gruppo') | 'singolo' | Tipo attività (gruppo non usato attivamente) |
| `ore` | decimal(10,2) | — | Ore lavorate in quella giornata |
| `note` | text | NULL | Note attività |
| `user_id` | int(11) FK | — | → `tb_utenti.id_utente` |
| `data_inserimento` | timestamp | CURRENT_TIMESTAMP | Timestamp inserimento record |

**Indici:** `idx_data (data_lavoro)`, `idx_progetto (progetto_id)`, `user_id`  
**Nota sicurezza:** Le query DELETE devono sempre includere `AND user_id = ?` per impedire cancellazioni cross-user.

---

### `tb_utenti`

Utenti dell'applicativo.

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id_utente` | int(11) PK AI | — | Identificativo |
| `username` | varchar(50) UNIQUE | — | Username login |
| `password_hash` | varchar(255) | — | Hash bcrypt (password_hash PHP) |
| `ruolo` | enum('user','admin','commercialista') | 'user' | Ruolo accesso |
| `email` | varchar(100) | NULL | Email (opzionale) |
| `data_creazione` | timestamp | CURRENT_TIMESTAMP | Creazione account |

**Utenti attuali:**
- `admin` (ruolo: admin)
- `palmangy` (ruolo: user) — utente operativo principale
- `commercialista` (ruolo: commercialista)

---

### `tb_impostazioni`

Tabella chiave-valore per configurazioni modificabili dall'interfaccia.

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id` | int(11) PK AI | — | Identificativo |
| `chiave` | varchar(50) UNIQUE | — | Chiave impostazione |
| `valore` | varchar(255) | — | Valore |
| `descrizione` | varchar(255) | NULL | Descrizione leggibile |

**Chiavi presenti:**

| Chiave | Valore attuale | Descrizione |
|--------|---------------|-------------|
| `prefisso_fattura` | `DOC` | Prefisso del numero fattura |
| `progressivo_fattura` | `4` | Ultimo progressivo usato (anno corrente) |
| `anno_progressivo` | `2025` | Anno a cui si riferisce il progressivo |

**Logica progressivo:** Se `anno_progressivo` ≠ anno corrente, il progressivo riparte da 1 e `anno_progressivo` viene aggiornato.

---

## Query ricorrenti utili

### Fatturato lordo anno corrente (corretto)
```sql
SELECT COALESCE(SUM(totale_fattura), 0) AS totale
FROM tb_fatture
WHERE anno = YEAR(CURDATE())
```

### Fatturato mensile con ore (due query separate — evita moltiplicazione da JOIN 1:N)
```sql
-- Fatturato per mese
SELECT mese, SUM(totale_fattura) AS totale_fatturato
FROM tb_fatture WHERE anno = ? GROUP BY mese ORDER BY mese;

-- Ore per mese
SELECT f.mese, COALESCE(SUM(fd.ore_erogate), 0) AS totale_ore
FROM tb_fatture_dettaglio fd
JOIN tb_fatture f ON fd.id_fattura = f.id_fattura
WHERE f.anno = ? GROUP BY f.mese;
```

### Verifica se una fattura ha già una fattura elettronica collegata
```sql
SELECT id_fattura_elettronica
FROM tb_fatture_elettroniche
WHERE numero_proforma = ?
```

### Ore mese corrente per utente
```sql
SELECT COALESCE(SUM(ore), 0)
FROM tb_ore_lavoro
WHERE MONTH(data_lavoro) = ? AND YEAR(data_lavoro) = ? AND user_id = ?
```

---

## Tabelle SaaS (migration 001_saas_foundation — 2026-05-25)

### `saas_plans`

Piani abbonamento disponibili.

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id` | int(11) PK AI | — | Identificativo |
| `name` | varchar(50) UNIQUE | — | Slug: `free` / `pro` |
| `display_name` | varchar(100) | — | Etichetta UI |
| `price_monthly` | decimal(10,2) | 0.00 | Prezzo EUR/mese (0 = gratuito) |
| `max_fatture_mese` | int(11) | NULL | Fatture/mese max (NULL = illimitato) |
| `max_clienti` | int(11) | NULL | Clienti max (NULL = illimitato) |
| `trial_days` | int(11) | 30 | Giorni trial (0 = nessun trial) |
| `is_active` | tinyint(1) | 1 | Piano visibile/attivo |
| `created_at` | timestamp | CURRENT_TIMESTAMP | — |

**Dati iniziali:** Free (3 fatture/mese, 2 clienti, no trial) · Pro (7€/mese, illimitato, 30gg trial)

---

### `saas_tenants`

Un record per ogni cliente SaaS registrato.

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id` | int(11) PK AI | — | Identificativo tenant |
| `owner_user_id` | int(11) FK | — | → `tb_utenti.id_utente` (utente principale) |
| `status` | enum('active','suspended','deleted') | 'active' | Stato account |
| `created_at` | timestamp | CURRENT_TIMESTAMP | — |

**Tenant 1** = dati storici esistenti (owner = admin).

---

### `saas_subscriptions`

Abbonamento attivo per ogni tenant (uno alla volta).

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id` | int(11) PK AI | — | — |
| `tenant_id` | int(11) FK | — | → `saas_tenants.id` |
| `plan_id` | int(11) FK | — | → `saas_plans.id` |
| `status` | enum('trial','active','expired','cancelled') | 'trial' | Stato abbonamento |
| `trial_ends_at` | timestamp | NULL | Fine trial (NULL = no trial) |
| `current_period_start` | date | NULL | Inizio periodo corrente |
| `current_period_end` | date | NULL | Fine periodo corrente |
| `payment_provider` | enum('paypal','stripe','none') | 'none' | Provider pagamento |
| `paypal_subscription_id` | varchar(255) | NULL | ID piano PayPal ricorrente (I-xxx) |
| `paypal_order_id` | varchar(255) | NULL | ID ultimo ordine/capture PayPal |
| `cancelled_at` | timestamp | NULL | Data cancellazione |
| `created_at` / `updated_at` | timestamp | CURRENT_TIMESTAMP | — |

---

### `saas_payments`

Storico pagamenti ricevuti.

| Colonna | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `id` | int(11) PK AI | — | — |
| `tenant_id` | int(11) FK | — | → `saas_tenants.id` |
| `subscription_id` | int(11) FK | — | → `saas_subscriptions.id` |
| `amount` | decimal(10,2) | — | Importo EUR |
| `currency` | char(3) | 'EUR' | — |
| `payment_provider` | varchar(50) | 'paypal' | — |
| `provider_payment_id` | varchar(255) | NULL | PayPal capture/order ID |
| `status` | enum('pending','completed','failed','refunded') | 'pending' | — |
| `paid_at` | timestamp | NULL | — |
| `created_at` | timestamp | CURRENT_TIMESTAMP | — |

---

### `saas_tenant_settings`

Impostazioni per-tenant (sostituisce `tb_impostazioni` in contesto multi-tenant).

| Colonna | Tipo | Descrizione |
|---------|------|-------------|
| `id` | int(11) PK AI | — |
| `tenant_id` | int(11) FK | → `saas_tenants.id` (CASCADE DELETE) |
| `chiave` | varchar(50) | Es. `prefisso_fattura`, `progressivo_fattura`, `anno_progressivo` |
| `valore` | varchar(255) | — |

**UNIQUE KEY** `(tenant_id, chiave)`. Ogni nuovo tenant ottiene una copia delle chiavi default al momento della registrazione.

---

### `saas_gdpr_exports`

Richieste di export dati (GDPR Art. 20 — portabilità).

| Colonna | Tipo | Descrizione |
|---------|------|-------------|
| `id` | int(11) PK AI | — |
| `tenant_id` | int(11) FK | → `saas_tenants.id` |
| `requested_by` | int(11) | `tb_utenti.id_utente` |
| `status` | enum('pending','processing','ready','expired') | Stato elaborazione |
| `download_token` | varchar(128) UNIQUE | Token monouso per il download |
| `token_expires_at` | timestamp | Scadenza token (es. 24h) |
| `requested_at` / `completed_at` | timestamp | — |

---

## Colonne aggiunte alle tabelle business (migration 001)

Tutte le tabelle business hanno ricevuto la colonna `tenant_id INT(11) NULL` per isolare i dati per tenant:

| Tabella | Colonna aggiunta | Note |
|---------|-----------------|------|
| `tb_utenti` | `tenant_id` + 5 colonne auth | `email_verified_at`, `verification_token`, `verification_token_exp`, `reset_token`, `reset_token_exp` |
| `tb_anagrafiche` | `tenant_id` | — |
| `tb_clienti` | `tenant_id` | — |
| `tb_progetti` | `tenant_id` | — |
| `tb_fatture` | `tenant_id` | — |
| `tb_fatture_dettaglio` | `tenant_id` | — |
| `tb_fatture_elettroniche` | `tenant_id` | — |
| `tb_impostazioni` | `tenant_id` | Usata solo per il tenant 1 legacy; nuovi tenant usano `saas_tenant_settings` |
| `tb_ore_lavoro` | `tenant_id` | — |

**Regola:** tutte le query sulle tabelle business devono sempre includere `AND tenant_id = ?` per garantire l'isolamento dei dati tra tenant.
