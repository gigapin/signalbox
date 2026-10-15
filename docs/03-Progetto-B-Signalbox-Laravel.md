# 03 — Progetto B: **Signalbox** (Laravel 13 + Inertia/React)

> **Che cos'è:** un SaaS multi-tenant di monitoraggio applicativo e *status page*: controlla endpoint
> HTTP, certificati TLS, record DNS e job cron ("dead man's switch"), apre e gestisce incidenti,
> notifica su più canali, pubblica una pagina di stato e fattura gli abbonamenti.
>
> **Perché questo progetto:** è il complemento perfetto di Ledgerly. Se il primo dimostra
> *modellazione di dominio e fondamenta*, questo dimostra *padronanza dell'ecosistema*: code ad alto
> volume, scheduling distribuito, real-time, notifiche, abbonamenti, API pubblica, multi-tenancy,
> front-end React. In pratica copre l'80% di ciò che un'agenzia ti chiederà di fare su Laravel.
>
> **Bonus strategico:** usa **Inertia 2 + React + TypeScript**, quindi mette in vetrina *insieme* le tue
> due competenze — il back-end PHP che stai riattivando e i due anni di React/Next della start-up.
>
> **Impegno stimato:** 130-150 ore → **8-9 settimane** a 3,5 h/giorno.

---

## 1. Stack

| Ambito | Scelta | Nota |
|---|---|---|
| Runtime | PHP 8.4 (CI anche su 8.5) | Laravel 13 richiede PHP 8.3+ |
| Framework | **Laravel 13** (rilasciato il 17 marzo 2026) | Bug fix fino a Q3 2027, sicurezza fino a Q1 2028 |
| Database | PostgreSQL 16 | Partizionamento dei risultati dei check per data |
| Cache/code/lock | Redis 7 + **Horizon** | Code separate per priorità |
| Real-time | **Reverb** (WebSocket di prima parte) | Dashboard live |
| Front-end | **Inertia 2 + React 19 + TypeScript + Tailwind 4** | SSR opzionale |
| Auth | Sessioni (web) + **Sanctum** (API) | Token con abilità/scope |
| Pagamenti | **Cashier / Stripe** in modalità test | Piani, trial, limiti per piano |
| Ricerca | Scout + database driver (Meilisearch opzionale) | |
| Test | **Pest 3**, `Http::fake`, `Queue::fake`, time travel | |
| Qualità | Larastan (PHPStan) livello 8, Pint, Rector | |
| Deploy | Docker + GitHub Actions → VPS o Laravel Cloud | Demo pubblica obbligatoria |

---

## 2. Il dominio

### Entità principali

- **Team** (tenant): gruppo con membri, ruoli, abbonamento, limiti di piano.
- **Monitor**: cosa controllare. Tipi:
  - `http` — URL, metodo, header, body atteso, codice atteso, soglia di latenza, follow redirect
  - `tls` — scadenza certificato, catena, hostname
  - `dns` — record atteso (A/AAAA/CNAME/MX/TXT), risolutore
  - `heartbeat` — l'opposto: è un *job esterno* che deve chiamarci entro N minuti, altrimenti allarme
    (dead man's switch per i cron dei clienti: feature molto richiesta e poco presente nei portfolio)
  - `keyword` — presenza/assenza di una stringa nella risposta
- **CheckResult**: esito di un singolo controllo (stato, latenza, codice, errore, regione, timestamp).
  È una **serie temporale**: cresce di milioni di righe, e va gestita come tale.
- **Incident**: aperto quando un monitor supera la soglia di conferma; ha una timeline di aggiornamenti,
  un responsabile, una causa dichiarata, e si chiude al ripristino.
- **NotificationChannel**: email, Slack, Telegram, webhook, SMS (stub). Con regole di escalation e
  *quiet hours*.
- **StatusPage**: pagina pubblica con monitor selezionati, raggruppati in componenti, storico 90 giorni,
  incidenti pubblicati, iscrizione via email agli aggiornamenti, dominio personalizzato.
- **Subscription**: piano Free / Pro / Business, con limiti su numero di monitor, frequenza minima,
  numero di status page, retention dei dati.

### I sette problemi tecnici veri (sono la sostanza del progetto)

1. **Distribuzione del carico dello scheduler.** Con 5.000 monitor a intervallo di 60 secondi, non puoi
   accodarli tutti allo scoccare del minuto. Serve *jitter* deterministico: ogni monitor ha uno slot
   calcolato (`crc32(monitor_id) % interval`) così il carico è uniforme nel tempo.
2. **Anti-flapping.** Un singolo timeout non è un guasto. Un incidente si apre solo dopo **N fallimenti
   consecutivi** (configurabile) e, idealmente, con conferma da una **seconda regione**. Simula le
   regioni con worker su code diverse (`checks:eu`, `checks:us`).
3. **Esecuzione concorrente e resiliente.** `Http::pool()` per controlli in parallelo, timeout stretti,
   `retry()` con backoff, e la garanzia che un endpoint lento non blocchi la coda
   (`WithoutOverlapping`, `timeout` sul job, `failed()` che registra invece di perdere).
4. **Volume dei dati.** I risultati grezzi si conservano 30 giorni; oltre, si aggregano in rollup orari
   e giornalieri (uptime %, p50/p95 latenza) con un job notturno. Le tabelle grezze sono partizionate
   per mese e vengono droppate, non cancellate riga per riga.
5. **Calcolo dell'uptime e dello SLA.** Non è "check ok / check totali": vanno considerate le finestre
   di manutenzione pianificata, i periodi in cui il monitor era in pausa e gli intervalli parziali.
   È una funzione che *va testata* con casi limite.
6. **Notifiche senza spam.** Deduplica, raggruppamento, escalation dopo X minuti, riepilogo di
   risoluzione, silenziamento durante le manutenzioni, rispetto delle quiet hours per fuso orario.
7. **Limiti di piano applicati sul serio.** Il limite non si controlla nel form: si applica con una
   policy centralizzata + un middleware sull'API + un job che sospende i monitor eccedenti quando un
   abbonamento decade.

Ognuno di questi punti è un paragrafo del README e una domanda a cui saprai rispondere in colloquio.

---

## 3. Schema (nucleo)

```
teams(id, name, slug, owner_id, plan, trial_ends_at, timezone, created_at)
team_user(team_id, user_id, role)                       -- owner|admin|member|viewer
users(id, name, email, password, two_factor_secret, current_team_id, ...)

monitors(id, team_id, type, name, url, method, headers_json, body, expected_status,
         expected_keyword, interval_seconds, timeout_ms, confirmation_threshold, regions_json,
         is_paused, last_status, last_checked_at, next_check_at, consecutive_failures,
         ssl_expires_at, created_at)
maintenance_windows(id, monitor_id, starts_at, ends_at, reason)

check_results(id, monitor_id, region, status, response_time_ms, status_code, error_code,
              error_message, checked_at)                -- partizionata per mese
check_rollups_hourly(monitor_id, hour, checks, failures, avg_ms, p95_ms, PRIMARY KEY(monitor_id, hour))
check_rollups_daily(monitor_id, day, checks, failures, uptime_ratio, avg_ms, p95_ms)

incidents(id, monitor_id, team_id, started_at, confirmed_at, resolved_at, severity, cause,
          root_cause_note, acknowledged_by, duration_seconds)
incident_updates(id, incident_id, author_id, status, message, is_public, created_at)

notification_channels(id, team_id, type, config_json, is_active, verified_at)
escalation_policies(id, team_id, name, steps_json)      -- step: dopo N minuti → canali X
notification_logs(id, incident_id, channel_id, status, response, sent_at)

status_pages(id, team_id, slug, custom_domain, title, description, theme_json, is_public, password_hash)
status_page_components(id, status_page_id, name, position, monitor_ids_json)
status_page_subscribers(id, status_page_id, email, verified_at, unsubscribed_at)

subscriptions / subscription_items                       -- tabelle di Cashier
api_tokens                                               -- tabelle di Sanctum
```

---

## 4. Milestone operative

| # | Milestone | Contenuto | Ore |
|---|---|---|---|
| **M0** | Setup | Laravel 13 + Sail/Docker, Postgres, Redis, Horizon, Reverb, Inertia+React+TS, Pint, Larastan, Pest, CI | 8 |
| **M1** | Auth e tenancy | Registrazione, login, 2FA, inviti via email, team, ruoli, cambio team, `TeamScope` globale su Eloquent, policy | 14 |
| **M2** | Monitor CRUD | Modelli, form request, validazione per tipo, UI React (lista, dettaglio, form), test | 14 |
| **M3** | Motore di check | Comando scheduler con jitter, job `RunCheck` per tipo, `Http::pool`, timeout, salvataggio risultati, `Http::fake` nei test | 18 |
| **M4** | Incidenti | Soglia di conferma, apertura/chiusura automatica, timeline, presa in carico, riaperture, finestre di manutenzione | 14 |
| **M5** | Notifiche | Canali (mail, Slack, Telegram, webhook firmato), escalation, quiet hours, deduplica, log di consegna, retry | 16 |
| **M6** | Dashboard real-time | Broadcasting con Reverb, canali privati per team, grafici latenza/uptime, aggiornamento live senza polling | 14 |
| **M7** | Aggregazioni e SLA | Rollup orari/giornalieri, partizioni, retention per piano, calcolo uptime con esclusioni, report mensile PDF | 12 |
| **M8** | Status page pubblica | Pagina pubblica cache-ata, componenti, storico 90 giorni, iscrizione email, dominio personalizzato, SSR/SEO | 14 |
| **M9** | Billing | Cashier + Stripe test, piani, trial, portale clienti, webhook Stripe, applicazione dei limiti, downgrade con sospensione | 14 |
| **M10** | API pubblica | Sanctum con abilità, risorse **JSON:API** (supporto nativo di Laravel 13), rate limit per piano, paginazione, OpenAPI | 12 |
| **M11** | Osservabilità e hardening | Pulse, log strutturati, health endpoint, tracciamento errori, security headers, audit delle azioni sensibili | 8 |
| **M12** | Deploy e presentazione | Docker multi-stage, GitHub Actions con deploy, demo pubblica con dati finti realistici, README, video 2 minuti | 10 |

**Totale: ~158 ore** → circa 9 settimane a 17,5 h/settimana. Se devi tagliare, sacrifica in
quest'ordine: M8 (dominio personalizzato), M7 (report PDF), M9 (portale clienti).
**Non tagliare mai M3, M4, M5**: sono il cuore tecnico.

---

## 5. Dettagli implementativi che vale la pena curare

**Scheduling.** In `routes/console.php`:
```php
Schedule::command('monitors:dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
```
`monitors:dispatch` seleziona `where('next_check_at', '<=', now())` a blocchi (`chunkById`), accoda i
job sulla coda della regione e aggiorna `next_check_at` **nella stessa transazione**, così due istanze
dello scheduler non accodano lo stesso monitor due volte.

**Job resiliente.**
```php
class RunHttpCheck implements ShouldQueue
{
    public int $tries = 1;              // il retry è di dominio, non di infrastruttura
    public int $timeout = 30;
    public function middleware(): array { return [new WithoutOverlapping($this->monitor->id)]; }
    public function failed(\Throwable $e): void { /* registra come check fallito, non perdere l'evento */ }
}
```
Nota da mettere nel README: *il retry automatico della coda è sbagliato per un sistema di monitoraggio* —
un tentativo fallito **è** l'informazione, non un errore da riprovare.

**Broadcasting.** Evento `CheckCompleted` su canale privato `team.{id}`, con payload minimo (id monitor,
stato, latenza). Il front-end React aggiorna lo stato locale via `useEcho`. Evita di ritrasmettere
l'intero modello: è la differenza tra una demo e un sistema che regge 5.000 monitor.

**Test caratteristici** (da citare esplicitamente nel README):
- `Http::fake()` con sequenze per simulare un endpoint che degrada e poi si riprende.
- `travel(5)->minutes()` per verificare escalation e soglie temporali.
- Test che l'incidente **non** si apre al primo fallimento con `confirmation_threshold = 3`.
- Test che un team Free non può creare il sesto monitor, né via UI né via API.
- Test di isolamento: un utente del team A non vede mai dati del team B (test parametrico su tutte le rotte).

---

## 6. Presentazione del repository

Stessa struttura del documento 02, con tre aggiunte specifiche:

1. **Demo online funzionante** con dati sintetici realistici (un monitor che "cade" ogni 30 minuti per
   3 minuti, così la status page ha uno storico credibile). Vale più di dieci screenshot.
2. **Sezione "Come regge il carico"**: numeri reali di un test locale (es. 5.000 monitor / minuto con
   4 worker), con il comando per riprodurlo. Anche numeri modesti, purché misurati, comunicano rigore.
3. **Sezione "Cosa non fa"**: niente sonde geografiche reali, niente SMS in produzione, retention
   limitata. Dichiarare i limiti è un segnale di maturità, non una debolezza.

---

## 7. Come i due progetti lavorano insieme nel tuo posizionamento

| | Ledgerly | Signalbox |
|---|---|---|
| Messaggio | "So progettare un back-end da zero" | "So costruire un prodotto completo con Laravel" |
| Interlocutore | Lead tecnico, CTO, colloquio approfondito | Titolare di agenzia, project manager, cliente finale |
| Dominio | Fiscale/contabile (PMI italiane) | Infrastruttura/DevOps (aziende tech) |
| Skill in vetrina | Fondamenta, dominio, correttezza | Ecosistema, scala, real-time, prodotto |
| Front-end | Assente o minimo | React + TypeScript + Inertia |

Insieme dicono una cosa sola, che è esattamente il tuo posizionamento: **"back-end PHP/Laravel solido,
con un front-end React moderno, e la capacità di portare un prodotto dall'idea alla produzione"**.
