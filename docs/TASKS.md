# Signalbox — Piano dei task

## Riferimenti e stato iniziale

Specifica: [03-Progetto-B-Signalbox-Laravel.md](03-Progetto-B-Signalbox-Laravel.md). Regole di lavoro: [AGENTS.md](../AGENTS.md). Dipendenze verificate e riferimento manifest: [DEPENDENCIES.md](DEPENDENCIES.md). Bootstrap e configurazioni iniziali: [BOOTSTRAP.md](BOOTSTRAP.md).

Alla pianificazione iniziale del 2026-10-15 era presente soltanto la specifica. M0.01 ha aggiunto manifest e lockfile; M0.02 ha inizializzato Git e lo scheletro Laravel/Inertia/React con avvio, test iniziali e build verificati. M0.03 aggiunge l’ambiente Docker locale; configurazione applicativa dei pacchetti e CI restano da implementare.

Stati: `[ ]` da fare, `[/]` in corso, `[x]` completato, `[!]` bloccato. Gli ID sono stabili: non rinumerarli. Per ogni task in corso o bloccato aggiungere una nota nel registro con dipendenze, evidenza e prossimo passo. Le checklist di milestone sono il riferimento operativo, non attestano implementazioni già presenti.

Le ore sono quelle delle singole righe della specifica: **178 ore complessive**, mentre il totale dichiarato è ~158 ore e l'introduzione indica 130–150 ore. Ristimare dopo M0, includendo documentazione capillare e controlli richiesti; non assumere 8–9 settimane come scadenza confermata.

## Criterio comune di completamento

Per ogni task: comportamento verificato, autorizzazioni e isolamento rispettati, documentazione in inglese per tutti gli elementi del codice come da AGENTS, test automatici pertinenti superati, PHP-CS-Fixer e controlli statici applicabili superati, piano aggiornato. Non completare una milestone prima dei suoi criteri di uscita. Annotare comandi e risultati effettivi nel registro di sessione.

## M0 — Setup e decisioni fondanti (8 h della specifica)

Dipendenze: nessuna. Sblocca tutte le milestone.

- [x] M0.01 Verificare matrice ufficiale PHP/Laravel/Inertia/React/Pest/Cashier e disponibilità JSON:API; fissare versioni e lockfile, registrando eventuali incompatibilità.
- [x] M0.02 Inizializzare Git e Laravel 13 con Inertia 2, React 19, TypeScript e Tailwind 4; configurare variabili di ambiente di esempio senza segreti.
- [x] M0.03 Preparare Docker/Sail con PHP 8.4, PostgreSQL 16, Redis 7 e processi web, queue, scheduler e Reverb; documentare avvio e reset locale.
- [x] M0.04 Installare e configurare Horizon, Reverb, Sanctum, Scout database driver e strumenti test; predisporre Cashier in modalità Stripe test.
- [ ] M0.05 Configurare PHP-CS-Fixer come formatter PHP di riferimento, Larastan livello 8, valutazione Rector, lint/format frontend e TypeScript rigoroso; documentare la sostituzione di Pint. PHP-CS-Fixer e Larastan livello 8 già configurati e verificati in M0.02; restano lint/format frontend, valutazione Rector e consolidamento.
- [ ] M0.06 Configurare Pest e test frontend, database di test PostgreSQL, factory iniziali e comandi unificati di verifica. Pest e quattro smoke test HTTP già operativi in M0.02; restano suite frontend e integrazione PostgreSQL.
- [ ] M0.07 Creare GitHub Actions con PHP 8.4/8.5, PostgreSQL/Redis, formatter dry-run, analisi, test e build frontend.
- [ ] M0.08 Registrare decisioni architetturali su confini di dominio, checker Strategy, tenant context, policy limiti e convenzioni di documentazione.
- [ ] M0.09 Definire semantica scheduler sotto-minuto, prenotazione/invio affidabile, retention/partizioni, SLA e p50/p95; definire matrice dei piani (Free: massimo cinque monitor; altri valori da decidere).

Uscita: ambiente riproducibile da checkout pulito, smoke test eseguito e controlli CI verdi per entrambe le versioni PHP.

## M1 — Autenticazione e tenancy (14 h)

Dipendenze: M0.

- [ ] M1.01 Creare utenti, team, membership, ruoli, timezone e team corrente con migration, factory e seeder.
- [ ] M1.02 Implementare registrazione, login/logout, recupero password, verifica email e 2FA con UI.
- [ ] M1.03 Implementare inviti email, accettazione, gestione membri e cambio team; definire scadenza inviti e vincoli sull'ultimo owner.
- [ ] M1.04 Implementare TeamScope, tenant context esplicito nei processi asincroni e policy per owner/admin/member/viewer.
- [ ] M1.05 Testare accessi diretti con ID di altri team, cambio team, inviti, ruoli e isolamento parametrico delle rotte disponibili; estendere la matrice a ogni milestone.

Uscita: nessun accesso tra tenant nei percorsi testati, inclusi job e scope; autenticazione e ruoli verificati.

## M2 — CRUD monitor (14 h)

Dipendenze: M1; matrice piani e contratti checker di M0.

- [ ] M2.01 Modellare Monitor con enum, configurazione tipizzata per HTTP/TLS/DNS/heartbeat/keyword, regioni, pause e campi di scheduling.
- [ ] M2.02 Creare Form Request e validazione per tipo: URL, metodo/header/body, status/keyword, DNS/resolver, TLS, intervalli, timeout e soglie.
- [ ] M2.03 Implementare protezioni SSRF per destinazioni, redirect, resolver e indirizzi risolti; definire gestione dei segreti e redazione degli output.
- [ ] M2.04 Implementare CRUD, pause/ripresa, policy e policy centralizzata dei limiti iniziali.
- [ ] M2.05 Creare UI lista/dettaglio/form, ricerca Scout, filtri, paginazione e stati vuoti/errore con accessibilità.
- [ ] M2.06 Testare tutti i tipi, input errati, permessi e isolamento; verificare che il sesto monitor Free sia rifiutato via UI anche con richieste concorrenti.

Uscita: tutti i tipi sono configurabili e persistiti correttamente; limiti applicati dal backend.

## M3 — Motore di check (18 h, nucleo obbligatorio)

Dipendenze: M2.

- [ ] M3.01 Implementare `monitors:dispatch`, jitter deterministico, selezione chunkById e avanzamento temporale senza deriva; rendere effettivi gli intervalli sotto il minuto se ammessi dai piani.
- [ ] M3.02 Garantire dispatch concorrente sicuro con prenotazione transazionale, lock distribuiti e strategia di recupero dal crash tra commit e invio; testare perdita e duplicazione dei job.
- [ ] M3.03 Implementare contratto e strategie HTTP e keyword con `Http::pool()`, redirect controllati, timeout e classificazione degli errori.
- [ ] M3.04 Implementare strategie TLS (scadenza, catena, hostname) e DNS (A/AAAA/CNAME/MX/TXT e resolver) con adapter sostituibili nei test.
- [ ] M3.05 Implementare heartbeat: endpoint con credenziale dedicata, scadenza, tolleranza e rilevamento delle chiamate mancanti.
- [ ] M3.06 Configurare job e code `checks:eu`/`checks:us`, WithoutOverlapping, timeout, `tries = 1`, `failed()` e idempotenza della persistenza; documentare eventuali retry di dominio con backoff.
- [ ] M3.07 Creare CheckResult con partizionamento mensile PostgreSQL, indici e gestione anticipata delle nuove partizioni.
- [ ] M3.08 Testare sequenze `Http::fake()`, endpoint lenti, errori TLS/DNS, heartbeat scaduti, pause, concorrenza, jitter e isolamento dei worker.

Uscita: controlli funzionanti per tutti i tipi, errori registrati, carico distribuito e nessun fallimento nascosto da retry infrastrutturali.

## M4 — Incidenti e manutenzioni (14 h, nucleo obbligatorio)

Dipendenze: M3.

- [ ] M4.01 Modellare incidenti, aggiornamenti, responsabilità, severità, causa e transizioni consentite.
- [ ] M4.02 Implementare fallimenti consecutivi, soglia configurabile e conferma da seconda regione simulata; definire comportamento con una sola regione.
- [ ] M4.03 Implementare apertura/chiusura automatica, presa in carico, aggiornamenti timeline e regole di riapertura con transazioni e vincoli contro duplicati.
- [ ] M4.04 Implementare finestre di manutenzione e tracciamento dei periodi di pausa necessari al futuro calcolo SLA.
- [ ] M4.05 Creare UI incidenti/timeline/manutenzioni con policy e pubblicazione esplicita degli aggiornamenti.
- [ ] M4.06 Testare che con soglia 3 il primo fallimento non apra incidenti, recupero, flapping, regioni discordanti, manutenzioni e risultati concorrenti o fuori ordine.

Uscita: ciclo guasto/ripristino coerente, nessun incidente duplicato e timeline verificabile.

## M5 — Notifiche (16 h, nucleo obbligatorio)

Dipendenze: M4.

- [ ] M5.01 Modellare canali, configurazioni protette, verifica/attivazione, policy di escalation e log di consegna; creare UI di gestione.
- [ ] M5.02 Implementare adapter email, Slack, Telegram e webhook firmato; includere SMS come stub dichiarato.
- [ ] M5.03 Implementare deduplica, raggruppamento, riepilogo di risoluzione e job di consegna idempotenti con retry/backoff e log redatti.
- [ ] M5.04 Implementare escalation dopo X minuti, annullamento al ripristino e silenziamento durante le manutenzioni.
- [ ] M5.05 Implementare quiet hours per timezone, intervalli oltre mezzanotte e cambi di ora legale.
- [ ] M5.06 Testare fake di notifiche/code/HTTP, time travel di cinque minuti, duplicati, firme, provider indisponibili e quiet hours.

Uscita: consegna verificata senza spam, escalation e soppressioni riproducibili con tempo simulato.

## M6 — Dashboard real-time (14 h)

Dipendenze: M3–M5.

- [ ] M6.01 Trasmettere CheckCompleted ed eventi incidenti dopo commit con payload minimo su canali privati `team.{id}`.
- [ ] M6.02 Autorizzare sottoscrizioni e testare il rifiuto degli utenti di altri team.
- [ ] M6.03 Integrare Reverb/Echo e hook React per aggiornamento locale, riconnessione e recupero dello stato dopo disconnessione senza polling periodico.
- [ ] M6.04 Creare dashboard con stato monitor, incidenti, latenza e uptime; usare il servizio SLA di M7 per i valori definitivi.
- [ ] M6.05 Testare payload, aggiornamenti UI, assenza di duplicati e sicurezza dei canali.

Uscita: dashboard aggiornata via WebSocket e grafici coerenti; chiusura definitiva dopo M7 per le metriche SLA.

## M7 — Aggregazioni, retention e SLA (12 h)

Dipendenze: M3–M4; completa le metriche di M6.

- [ ] M7.01 Implementare rollup orari/giornalieri idempotenti con uptime, conteggi, media, p50 e p95; estendere lo schema esemplificativo dove necessario.
- [ ] M7.02 Definire percentili esatti o approssimati e dati aggregabili da conservare: non mediare i percentili orari per ottenere quelli giornalieri.
- [ ] M7.03 Implementare servizio SLA pesato sul tempo con esclusione di manutenzioni/pause, intersezioni e intervalli parziali; definire assenza dati e denominatore nullo.
- [ ] M7.04 Implementare job notturno, creazione/drop partizioni, retention per piano e verifica dei rollup prima della rimozione; rispettare la politica concordata sui 30 giorni.
- [ ] M7.05 Implementare report mensile PDF con autorizzazione tenant e timezone corretta.
- [ ] M7.06 Testare confini mese/anno, timezone/DST, finestre sovrapposte, pause storiche, periodi senza check, percentili e riesecuzione dei job su PostgreSQL.

Uscita: SLA documentato e testato, storico utilizzabile dopo eliminazione dei grezzi e manutenzione partizioni verificata.

## M8 — Status page pubblica (14 h)

Dipendenze: M4–M5, M7.

- [ ] M8.01 Implementare status page, componenti ordinati, selezione monitor dello stesso team, tema, visibilità e protezione password.
- [ ] M8.02 Pubblicare stato, storico di 90 giorni e soli aggiornamenti incidenti esplicitamente pubblici.
- [ ] M8.03 Configurare cache con chiavi tenant/pagina, invalidazione su modifiche e protezione delle pagine riservate.
- [ ] M8.04 Implementare iscrizione email verificata, disiscrizione e invio degli aggiornamenti pubblici.
- [ ] M8.05 Implementare dominio personalizzato con verifica di proprietà e routing sicuro; definire gestione TLS nel deploy.
- [ ] M8.06 Curare SEO e metadati; valutare SSR opzionale in base alla resa pubblica richiesta.
- [ ] M8.07 Testare selezione cross-tenant, dati privati, password/cache, subscriber e domini non verificati.

Uscita: pagina pubblica accessibile, storico da rollup disponibile e nessuna esposizione di dati riservati.

## M9 — Billing e limiti (14 h)

Dipendenze: M1–M2, M7; matrice piani definita in M0.

- [ ] M9.01 Configurare Cashier/Stripe test, piani Free/Pro/Business, trial, subscription e subscription_items.
- [ ] M9.02 Implementare checkout, cambio piano, cancellazione e portale clienti con UI degli stati di abbonamento.
- [ ] M9.03 Verificare firma dei webhook Stripe e gestire eventi duplicati/fuori ordine, rinnovi e decadenza.
- [ ] M9.04 Completare policy centrale per monitor, frequenza minima, status page e retention; applicarla a tutte le mutazioni e ai job.
- [ ] M9.05 Implementare sospensione deterministica dei monitor eccedenti al downgrade/scadenza e comportamento di successivo upgrade.
- [ ] M9.06 Testare trial, limiti, downgrade, webhook replay e concorrenza; verificare che nessun percorso aggiri le policy.

Uscita: stato abbonamento coerente con i limiti effettivi, anche dopo eventi asincroni.

## M10 — API pubblica (12 h)

Dipendenze: M2, M4, M7, M9; disponibilità JSON:API verificata in M0.

- [ ] M10.01 Definire endpoint e contratto JSON:API per risorse esposte, errori, relazioni, filtri e paginazione; documentare l'eventuale adapter necessario.
- [ ] M10.02 Implementare token Sanctum con abilità, revoca e policy tenant; distinguere API autenticata ed endpoint heartbeat.
- [ ] M10.03 Implementare middleware limiti e rate limit per piano con risposte e header coerenti.
- [ ] M10.04 Scrivere OpenAPI con esempi di autenticazione, errori, paginazione e limiti.
- [ ] M10.05 Testare scope insufficienti, token revocati, isolamento parametrico di tutte le rotte, contratto e sesto monitor Free rifiutato anche via API.

Uscita: API documentata e verificata automaticamente, con gli stessi vincoli della UI.

## M11 — Osservabilità e hardening (8 h)

Dipendenze: M3–M10; sicurezza e logging di base iniziano nelle milestone precedenti.

- [ ] M11.01 Configurare Pulse, Horizon protetto e health endpoint con controlli infrastrutturali e output pubblico minimo.
- [ ] M11.02 Implementare log strutturati, correlazione check/job/incidente, metriche code e tracciamento errori con redazione dei segreti.
- [ ] M11.03 Registrare audit delle azioni sensibili con attore, tenant, azione e timestamp.
- [ ] M11.04 Verificare security headers, CSRF, XSS, SSRF/DNS rebinding, webhook, autorizzazioni, rate limit e gestione credenziali.
- [ ] M11.05 Testare health degradato, accessi agli strumenti interni e audit; documentare diagnosi, backup e prova di ripristino.

Uscita: guasti diagnosticabili e controlli di sicurezza verificati sui percorsi sensibili.

## M12 — Deploy e presentazione (10 h)

Dipendenze: M0–M11.

- [ ] M12.01 Preparare Docker multi-stage, configurazione produzione e pipeline GitHub Actions verso VPS o Laravel Cloud con gestione sicura dei segreti.
- [ ] M12.02 Documentare ed eseguire migration, deploy, restart worker, scheduler, Reverb, TLS, backup e rollback; verificare health e smoke test dopo deploy.
- [ ] M12.03 Preparare demo pubblica con dati sintetici realistici e monitor che simula tre minuti di guasto ogni trenta minuti.
- [ ] M12.04 Eseguire benchmark riproducibile (riferimento: 5.000 monitor/minuto, quattro worker), registrando hardware, concorrenza, latenza, throughput e backlog reali senza promettere risultati non misurati.
- [ ] M12.05 Scrivere README con setup, architettura, sette problemi tecnici, test caratteristici, comandi benchmark, sezione “Come regge il carico” e “Cosa non fa”.
- [ ] M12.06 Produrre video di due minuti e collegamenti alla demo; esplicitare regioni simulate, SMS stub e limiti di retention.
- [ ] M12.07 Eseguire verifica finale da checkout pulito, suite completa, CI PHP 8.4/8.5 e smoke test dei flussi essenziali sulla demo.

Uscita: demo raggiungibile, deploy riproducibile, documentazione e prove consultabili.

## Ordine operativo e decisioni aperte

Percorso iniziale: M0 → M1 → M2 → M3 → M4 → M5. Proseguire con M6 e M7, quindi M8/M9, M10, M11 e M12 rispettando le dipendenze. M6 può iniziare prima dei rollup, ma le metriche definitive dipendono da M7. Sicurezza, documentazione e test sono trasversali, non rinviati a M11.

Compatibilità e JSON:API verificati in M0.01 (vedere DEPENDENCIES.md). Da risolvere nel resto di M0: valori Pro/Business e trial; semantica conferma regionale e retry di dominio; finestre SLA e dati mancanti; retention esatta o arrotondata alle partizioni mensili; percentili aggregabili; garanzie del dispatch in caso di crash. La specifica cita un documento 02 non presente: non inventarne i requisiti; preparare README secondo le indicazioni disponibili.

Possibili riduzioni, soltanto dopo decisione esplicita: dominio personalizzato M8, report PDF M7, portale clienti M9, in questo ordine. M3/M4/M5 restano obbligatorie.

## Registro decisioni

| Data | Decisione | Motivazione / conseguenze |
|---|---|---|
| 2026-10-15 | PHP-CS-Fixer è il formatter PHP richiesto | Istruzione utente; sostituisce Pint come riferimento per evitare conflitti. Configurazione da implementare in M0. |
| 2026-10-15 | Piano suddiviso in M0–M12, senza avvio implementazione | Questa sessione riguarda lettura e pianificazione; tutte le attività applicative restano aperte. |
| 2026-10-15 | Stack verificato con Pest 4 e Inertia 2 esplicito | Plugin Pest 3 non supporta Laravel 13; starter React attuale usa Inertia 3. Vincoli e lockfile in root, dettagli in DEPENDENCIES.md. |
| 2026-10-15 | Baseline PHP 8.4, Node 22/npm 10 e Predis 3 | Composer risolve per PHP 8.4; runtime locale 8.5 verificato. Client Redis senza estensione locale aggiuntiva. |
| 2026-10-15 | Bootstrap senza accessi infrastrutturali impliciti | Sessioni/cache locali su file e coda sync temporanea; PostgreSQL e Predis restano i driver selezionati. Configurazione Redis/worker in M0.03. |

## Registro sessioni

### 2026-10-15 — Pianificazione iniziale

- Completato: lettura integrale della specifica, creazione di `AGENTS.md` e di questo piano.
- Stato applicazione: non inizializzata; nessuna milestone applicativa completata.
- Verifiche: revisione della copertura rispetto alla specifica; nessun test applicativo eseguibile in questa fase.
- Rilevato: stime incoerenti nella specifica, documento 02 assente, repository Git non inizializzato.
- Prossimo passo: M0.01, verifica della matrice di compatibilità, poi bootstrap e decisioni fondanti.

Per le prossime sessioni aggiungere: data, ID task e stato, modifiche/file, comandi di verifica con esito, decisioni, blocchi e prossimo passo concreto.

### 2026-10-15 — M0.01: verifica e blocco delle dipendenze

- Completato M0.01: fonti ufficiali consultate, vincoli espliciti, risoluzione stabile e lockfile reali senza installazione o esecuzione dei pacchetti.
- File: `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `docs/DEPENDENCIES.md`; aggiornati `AGENTS.md` e questo piano.
- Decisioni: Pest 4/plugin Laravel 4 sostituiscono Pest 3 per compatibilità con Laravel 13; mantenuti Inertia 2 e React 19 con bootstrap manuale (lo starter attuale usa Inertia 3); PHP di risoluzione 8.4.0, verifica locale su 8.5.9; Node 22/npm 10, Vite 8/plugin Laravel 3; Predis 3 come client Redis iniziale.
- Verifiche superate: `composer update --no-install --no-scripts --no-plugins --no-interaction --prefer-dist` (157 pacchetti, nessun advisory noto), `npm install --package-lock-only --ignore-scripts --strict-peer-deps` (138 pacchetti analizzati, nessun conflitto peer o vulnerabilità nota), `composer validate --strict --no-check-publish`, `composer check-platform-reqs --lock` su PHP 8.5.
- Limiti: nessuna applicazione o dipendenza installata; test, build e PHP 8.4 reale ancora da verificare in M0.02/M0.03/M0.07. CI non creata né eseguita. M0 non è completa.
- Prossimo passo: M0.02, inizializzare Git e Laravel preservando documenti, manifest e lock, poi collegare Inertia 2/React e verificare avvio/build.

### 2026-10-15 — M0.02: bootstrap applicazione

- Completato M0.02: Git inizializzato su `main`, scheletro Laravel 13 con Inertia 2/React 19/TypeScript/Tailwind 4, pagina iniziale, template `.env.example` commentato senza segreti e chiave generata soltanto nel `.env` locale ignorato.
- File principali: `bootstrap/`, `app/`, `config/`, `routes/`, `resources/`, `database/`, `tests/`, entry point PHP, `vite.config.ts`, `tsconfig.json`, `phpunit.xml`, `.php-cs-fixer.dist.php`, `phpstan.neon`, `README.md`, `docs/BOOTSTRAP.md`; aggiornati i manifest e il riferimento dipendenze senza cambiare versioni risolte.
- Anticipazioni utili: baseline PHP-CS-Fixer, Larastan livello 8 e Pest; M0.05/M0.06 restano aperti per le parti mancanti. I pacchetti di M0.04 sono installati ma non ancora configurati come funzionalità.
- Verifiche superate: `composer install --no-scripts --no-interaction --prefer-dist`, `npm ci --ignore-scripts --strict-peer-deps`, `composer dump-autoload --no-interaction`, discovery package, `php artisan about --only=environment`, `composer validate --strict --no-check-publish`, `composer test` (4 test, 26 asserzioni), `composer analyse` (livello 8, zero errori), `npm run build` (TypeScript e Vite), `composer format` e successivo `composer format:check`.
- Smoke HTTP reale superato: home HTML, risposta JSON Inertia con versione asset, `/up` e tre asset compilati; server temporaneo arrestato. Verificata esclusione Git di `.env`, vendor, node_modules, build e cache.
- Limiti: verifiche eseguite su PHP 8.5.9; runtime PHP 8.4, database/Redis, migration, test browser/frontend e CI ancora da verificare. Nessun commit o remote creato. M0 complessiva ancora aperta.
- Prossimo passo: M0.03, ambiente Docker con PHP 8.4, PostgreSQL 16, Redis 7 e processi distinti web/queue/scheduler/Reverb.

### 2026-10-15 — M0.03: ambiente Docker

- Dipendenze M0.01/M0.02 soddisfatte; lavoro sul branch `chore/docker`, destinazione `staging` e successivamente `main`.
- Docker daemon inizialmente non disponibile, poi avviato; build e prove reali eseguite sul motore locale.
- Decisione: Docker Compose diretto, senza aggiungere Sail; processi separati e riuso della stessa immagine PHP. Reverb predisposto come processo opzionale fino alla configurazione applicativa M0.04.
- File: `compose.yaml`, `.dockerignore`, `docker/php/Dockerfile`, `docker/php/local.ini`, `docker/node/Dockerfile`, `.env.example`, `tests/Feature/DockerInfrastructureTest.php`, `docs/DOCKER.md`; aggiornati README, bootstrap, dipendenze e piano.
- Configurazione: immagini ufficiali fissate per digest multiarch, PHP 8.4.26, PostgreSQL 16, Redis 7 persistente, processi non-root, health gate per database/Redis, web su loopback, queue con un tentativo e timeout 30 s, scheduler foreground; Node 22/npm 10 con volume dipendenze separato dal Mac. Nessun cambio ai lockfile.
- Generati soltanto nel `.env` ignorato password PostgreSQL e credenziali Reverb locali; chiave Laravel preesistente preservata. Porta 8080 già occupata da altro servizio: `REVERB_FORWARD_PORT=18080` nel solo `.env` locale, senza modificare altri container.
- Verifiche superate: `docker compose config --quiet`, build immagini PHP/Node, Composer install/discovery e `check-platform-reqs` su PHP 8.4.26; migration iniziali sul nuovo PostgreSQL; avvio web/queue/scheduler; formatter dry-run sequenziale (32 file), Larastan livello 8 (zero errori); `docker compose exec -e RUN_DOCKER_TESTS=1 web php vendor/bin/pest` (7 test, 38 asserzioni). Suite ordinaria host: 4 passati/3 integrazione saltati come previsto.
- Prove reali: home HTTP e asset compilati serviti; processo scheduler attivo senza task di dominio; Reverb avviato con upgrade WebSocket HTTP 101; web/PostgreSQL/Redis healthy. Test infrastrutturali: transazione PostgreSQL con rollback, cache Redis, esclusione lock e prenotazione/eliminazione su coda Redis dedicata. Nessuna cancellazione dei volumi applicativi eseguita.
- Note operative: PHP-CS-Fixer locale parallelo impedito dal sandbox, eseguito con `--sequential`; Larastan con `--debug` per evitare worker TCP nel sandbox. Primo pin Composer errato corretto prima della build riuscita; interruzione temporanea rete npm nel volume isolato risolta ripetendo `npm ci --prefer-offline`. Dipendenze native del Mac ripristinate tramite `npm ci --ignore-scripts --strict-peer-deps`.
- Limiti: nessuna prova browser interattiva, CI PHP 8.4/8.5 o installazione completa da checkout pulito ancora eseguita; restano rispettivamente M0.06/M0.07 e criterio finale di M0. Horizon, broadcasting e policy origini/credenziali Reverb applicative restano M0.04; nessun checker o scheduler di dominio anticipato. Nessun commit/push/merge eseguito.
- Completato M0.03: build finale frontend nel volume isolato e TypeScript host superati; guida di primo avvio, uso quotidiano e reset disponibile in `docs/DOCKER.md`. Servizi lasciati attivi per la revisione locale. Prossimo passo: M0.04, configurazione applicativa Horizon/Reverb/Sanctum/Scout/Cashier; M0 complessiva resta aperta.

### 2026-10-15 — M0.04: configurazione pacchetti

- Dipendenze M0.01–M0.03 soddisfatte; implementazione autorizzata dall’utente sul branch da lui gestito. Nessun comando Git/gh eseguito.
- La regola Git non risultava nel file AGENTS presente dopo il cambio branch: ripristinata come da richiesta esplicita persistente dell’utente.
- Obiettivo: Horizon con code separate e accesso negato per default, Reverb con origini esplicite e trasporto server distinto dall’indirizzo browser, Sanctum con storage token, Scout database driver, Cashier predisposto solo per chiavi test senza endpoint billing prematuri.
- Confini: niente autenticazione UI, tenancy, monitor ricercabili, checkout o webhook billing; tali comportamenti restano M1/M2/M9/M10.
- File applicativi: `config/{horizon,reverb,broadcasting,sanctum,scout,cashier}.php`, `app/Providers/{AppServiceProvider,HorizonServiceProvider}.php`, `app/Actions/EnsureStripeTestCredentials.php`, `app/Models/User.php`, `bootstrap/{app,providers}.php`, migration `2026_10_15_000003_create_personal_access_tokens_table.php`, `routes/console.php`, `compose.yaml`, `.env.example`.
- Test/documenti: `tests/Feature/{ServicesConfigurationTest,ReverbConfigurationTest,ServicesDatabaseTest}.php`, `tests/Fixtures/SearchDocument.php`, `phpunit.xml`, `docs/SERVICES.md`; aggiornati README, Docker/bootstrap/dipendenze e AGENTS. Nessun manifest o lockfile modificato.
- Decisioni: tre supervisor Horizon indipendenti (`default`, `checks:eu`, `checks:us`), un tentativo, timeout 30 s sotto retry_after 90 s, grace Compose 60 s; accesso globale solo a email operatore esplicitamente elencata e verificata, senza bypass local. Snapshot ogni cinque minuti con lock anti-sovrapposizione.
- Decisioni: Reverb opzionale con hostnames locali ammessi, limiti messaggi/connessioni, eventi client disabilitati e rate limit; backend pubblica su DNS Docker `reverb:8080`, distinto dalla porta browser locale 18080. Broadcast log resta il default offline; eventi/canali privati/Echo sono M6.
- Decisioni: Sanctum con token hash, abilità e TTL iniziale 24 h (non limite di piano); nessun endpoint API anticipato. Scout fissato al database senza modelli applicativi ancora ricercabili; test su relazione temporanea con vincolo proprietario esplicito, senza dichiarare implementata la tenancy.
- Decisioni Cashier: boot consente chiavi vuote o prefissi test, rifiuta live/prefissi sconosciuti senza stampare valori; rotte package disabilitate. Migration billing e trait Billable differiti a Team/M9 per evitare l’errata associazione agli utenti: predisposizione M0, non riduzione dello scope billing.
- Verifiche PHP 8.4.26 Docker superate: `docker compose config --quiet`; migration Sanctum sul solo PostgreSQL locale; `php vendor/bin/pest` con `RUN_DOCKER_TESTS=1` (31 test, 99 asserzioni); formatter dry-run sequenziale (45 file); Larastan livello 8 zero errori. `config:cache` e successivo `config:clear` riusciti; configurazione di sviluppo lasciata non cachata.
- Suite host PHP 8.5.9 superata: `composer test -- --compact` (24 test, 75 asserzioni; 7 integrazioni saltate come previsto).
- Prove reali: Horizon running con tre supervisor e un worker per coda; snapshot memorizzato e schedule visibile; pubblicazione firmata a Reverb riuscita, firma errata rifiutata con HTTP 401; home e `/up` HTTP 200, `/horizon` anonimo HTTP 403. Servizi locali lasciati attivi.
- Correzioni durante le verifiche: Mockery generava una firma PHP invalida sul contratto Connection di Reverb; sostituito con connessione/protocollo reali e solo trasporto WebSocket fake, senza modificare vendor o versioni. Il test supervisor ora legge `maxTries`, nome effettivo delle opzioni Horizon, anziché il nome grezzo `tries` della configurazione.
- Limiti: niente richieste reali a Stripe (non necessarie per la predisposizione test), flusso browser real-time, endpoint tenant/API o billing. Frontend TypeScript/build non rieseguiti perché nessun file frontend o dipendenza è cambiato. CI, database di test dedicato e prova da checkout pulito restano nei task successivi di M0. Nessun comando Git/gh eseguito; gestione branch/commit/PR riservata all’utente.
- Completato M0.04 per il perimetro di setup; prossimo passo M0.05 (lint/format frontend, valutazione Rector e consolidamento dei controlli). M0 complessiva ancora aperta.
