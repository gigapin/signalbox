# Signalbox — Piano dei task

## Riferimenti e stato iniziale

Specifica: [03-Progetto-B-Signalbox-Laravel.md](03-Progetto-B-Signalbox-Laravel.md). Regole di lavoro: [AGENTS.md](../AGENTS.md). Dipendenze verificate e riferimento manifest: [DEPENDENCIES.md](DEPENDENCIES.md). Bootstrap e configurazioni iniziali: [BOOTSTRAP.md](BOOTSTRAP.md).

Alla pianificazione iniziale del 2026-10-15 era presente soltanto la specifica. M0.01 ha aggiunto manifest e lockfile; M0.02 ha inizializzato Git e lo scheletro Laravel/Inertia/React con avvio, test iniziali e build verificati. M0.03 aggiunge l’ambiente Docker locale, M0.04 configura i pacchetti e M0.05 completa i controlli locali di qualità; M0.06 introduce test DOM e servizi test isolati; M0.07 implementa la CI, con esiti remoti positivi confermati dall’utente anche per PHP 8.5 su main.

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
- [x] M0.05 Configurare PHP-CS-Fixer come formatter PHP di riferimento, Larastan livello 8, valutazione Rector, lint/format frontend e TypeScript rigoroso; documentare la sostituzione di Pint. Baseline PHP di M0.02 confermata; completati lint/format frontend, controllo TypeScript, test degli strumenti e valutazione Rector.
- [x] M0.06 Configurare Pest e test frontend, database di test PostgreSQL, factory iniziali e comandi unificati di verifica. Completati suite Unit/Feature/Integration, test DOM, servizi test effimeri separati, verifica factory User e comando completo.
- [x] M0.07 Creare GitHub Actions con PHP 8.4/8.5, PostgreSQL/Redis, formatter dry-run, analisi, test e build frontend.
- [x] M0.08 Registrare decisioni architetturali su confini di dominio, checker Strategy, tenant context, policy limiti e convenzioni di documentazione.
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
| 2026-10-16 | ADR-001–ADR-005 adottati in M0.08 | [ARCHITECTURE.md](ARCHITECTURE.md): monolite modulare con action coese; Strategy/adapter; tenant context esplicito con rifiuto in sua assenza; policy e limiti centralizzati; documentazione inglese e record evolutivi. Implementazione progressiva M1–M12, senza classi speculative; semantiche quantitative/affidabilità restano M0.09. |

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

### 2026-10-15 — M0.05: qualità del codice

- Dipendenze: bootstrap e pacchetti M0.01–M0.04 completati. Nessun comando Git/gh; branch e pubblicazione gestiti dall’utente.
- Obiettivo: ESLint per TypeScript/React, Prettier per frontend e relativi manifest, consolidamento TypeScript rigoroso e verifiche PHP esistenti, valutazione documentata di Rector. CI resta M0.07 e test UI M0.06.
- File: `eslint.config.js`, `prettier.config.js`, `.prettierignore`, `scripts/quality.test.mjs`, `package.json`, `package-lock.json`, `tsconfig.json`, `.gitignore`; normalizzato il layout di `resources/js/pages/Home.tsx` e `vite.config.ts`, sostituita la reference Vite con import dichiarativo in `resources/js/vite-env.d.ts`. Documenti: nuovo `docs/QUALITY.md`, aggiornati README, bootstrap e dipendenze. Nessuna modifica ai manifest/lock Composer o alla logica di dominio.
- Versioni nuove esatte: ESLint 10.12.0, @eslint/js 10.0.1, typescript-eslint 8.71.2, plugin React Hooks 7.1.1, eslint-config-prettier 10.1.8, Prettier 3.9.10, globals 17.13.0. Node resta 22 ma il minimo sale da 22.12 a 22.13 per ESLint 10; npm resta 10. Verificato che tutte le versioni dirette frontend preesistenti corrispondano alla matrice M0.01.
- Decisioni: lint typed tramite project service, raccomandazioni React Hooks e fallimento su warning; Prettier separato per TS/TSX/CSS/JS e manifest selezionati, senza PHP/Blade/Markdown/YAML né lockfile generati. PHP-CS-Fixer resta unico formatter PHP e Larastan livello 8 senza baseline/soppressioni nuove. Nessun hook Git installato.
- Compatibilità: inizialmente valutato ESLint 9 per il plugin JSX accessibility; rilevato EOL ufficiale di ESLint 9 e peer del plugin limitati a 9, eliminati entrambi dalla selezione finale. Scelto ESLint 10 supportato con peer stretti, senza override. Accessibilità resta requisito di feature e test UI M0.06; non dichiarata copertura statica JSX accessibility.
- TypeScript: mantenuti strict/noUncheckedIndexedAccess e aggiunti noUnusedLocals, noUnusedParameters, noImplicitReturns, noFallthroughCasesInSwitch, exactOptionalPropertyTypes, verbatimModuleSyntax. Nuovi campi JSON documentati in QUALITY/DEPENDENCIES.
- Rector valutato ma non installato: nessuna migrazione legacy o trasformazione semantica ripetuta richiesta sul nuovo codice PHP 8.4/Laravel 13. Registrato workflow futuro con versione/regole/path limitati, dry-run revisionabile e verifiche successive; nessuna esecuzione o compatibilità runtime di Rector inventata.
- Test automatici nuovi: cinque prove Node con configurazioni reali per accettare React valido, rifiutare hook condizionali/promesse non gestite, rifiutare undefined su proprietà opzionali esatte, verificare formato e preservazione commenti, ignorare bundle generati. Fixture uniche sotto resources/js rimosse in finally e ignorate da Git in caso di interruzione; nessuna rete o attesa nei test.
- Verifiche npm superate: installazione peer stretta e aggiornamento lock con script lifecycle disabilitati; `npm run check` host e Docker (lint, formato, TypeScript, 5 test tutti passati); `docker compose run --rm node npm ci --ignore-scripts --strict-peer-deps` e `npm run build` nel container; audit npm a zero advisory noti al momento della verifica. Controllata corrispondenza manifest/lock ed eliminazione delle fixture; controllo whitespace senza Git riuscito.
- Verifiche PHP superate su 8.4.26 Docker: formatter dry-run sequenziale su 45 file, Larastan livello 8 zero errori, Pest con RUN_DOCKER_TESTS=1: 31 test e 99 asserzioni. Formatter e Larastan superati anche su PHP host 8.5.9 (con il noto avviso formatter sulla baseline 8.4).
- Correzione durante le prove: i commenti tra argomenti delle callback di test causavano riposizionamenti tra passate Prettier; usate funzioni di test nominate con documentazione sulla dichiarazione, verificando poi formato stabile. Nessuna regola disabilitata per aggirare errori applicativi.
- Limiti espliciti: niente test UI/browser, CI, database di test dedicato o checkout pulito ancora verificati; restano M0.06/M0.07 e criterio finale M0. Nessuna nuova funzionalità prodotto. Nessun comando Git/gh eseguito.
- Completato M0.05; prossimo passo M0.06: test frontend, database PostgreSQL dedicato ai test, factory e comandi unificati di verifica. M0 complessiva resta aperta.

### 2026-10-15 — M0.06: infrastruttura test

- Dipendenze M0.01–M0.05 soddisfatte. Nessun comando Git/gh; branch gestito dall’utente.
- Obiettivo: Vitest/Testing Library, suite Unit/Integration Pest, factory User verificate, PostgreSQL/Redis test separati ed effimeri, guardie prima di qualsiasi migration, comando completo riproducibile senza processi applicativi avviati.
- Confini: nessuna UI di dominio o tenancy anticipata; niente SQLite, nessun reset del database/Redis di sviluppo. CI resta M0.07.
- File backend: `compose.yaml`, `phpunit.xml`, `tests/{Pest,TestCase}.php`, `tests/Support/TestDatabaseGuard.php`, `tests/Unit/TestDatabaseGuardTest.php`, `tests/Integration/{DockerInfrastructureTest,ServicesDatabaseTest,UserFactoryTest}.php`, `tests/Feature/TestHarnessTest.php`. I precedenti test DB/Redis sono spostati da Feature a Integration; RefreshDatabase migra soltanto il database dedicato e sostituisce le transazioni manuali dei test Sanctum/Scout.
- File frontend/tooling: `vitest.config.ts`, `tests/frontend/{setup,renderInertia,Home.test,Inertia.test}.{ts,tsx}` secondo estensione effettiva, `scripts/verify.sh` eseguibile, manifest npm/lock, `composer.json` (solo script verify), tsconfig, ESLint e ignore dei report. Documenti: nuovo `docs/TESTING.md`, aggiornati README, bootstrap, dipendenze, Docker, services e quality. Nessuna logica applicativa o migration di dominio aggiunta.
- Decisioni: servizi `postgres-test`/`redis-test` separati, senza porte host e persistenza, profilo testing; ruolo/database fissi signalbox_test con sole credenziali pubbliche fixture. Runner php-test con opt-in esplicito; nessun worker/scheduler/web richiesto per verificare il progetto. Hostname sviluppo, URL, driver/identità errati e configurazione cachata rifiutati prima di mutation; identità server letta prima di RefreshDatabase. Guardie pure coperte da 12 casi.
- Pest: suite Unit/Feature/Integration; attivazione RefreshDatabase solo con RUN_DOCKER_TESTS=1 per evitare connessioni quando i test integrazione sono scoperti ma saltati. Confermati factory User verificata/non verificata, password hash, email distinte e serialization protetta; nessuna factory di dominio anticipata. Aggiunta barriera Http::preventStrayRequests con test che prova il rifiuto prima della rete.
- Compatibilità verificata tramite metadati npm ufficiali e peer stretti: Vitest 5.0.5, jsdom 30.1.3, Testing Library React 16.3.3, DOM 10.4.2, user-event 14.6.8, jest-dom 7.0.1. Node resta 22/npm 10; minimo Node alzato da 22.13.0 a 22.22.2 per jsdom. Confermate tutte le versioni dirette preesistenti, incluse React/Inertia/Vite e qualità M0.05; nessuna modifica a composer.lock necessaria per il solo script.
- Frontend: contesto Inertia reale tipizzato dall’adapter; tre test per shell/landmark, escaping props server e attivazione da tastiera del Link reale con solo router/trasporto intercettato. Fetch/XHR inattesi bloccati, DOM e clock ripuliti; nessuna rete reale, nessun sleep o nuova UI prodotto. Il Link è una fixture test, non una feature implementata. Node quality suite resta separata (5 prove).
- Comandi: `composer verify` aggrega formatter dry-run sequenziale, Larastan e Pest; `npm run verify` aggrega qualità, DOM test e build; `./scripts/verify.sh` esegue entrambe le parti in Docker con fail-fast. Aggiornate le istruzioni che prima lanciavano integrazioni dal container web: ora usare esclusivamente php-test o servizi CI dedicati.
- Verifiche Docker complete superate tramite il nuovo `./scripts/verify.sh`: PHP 8.4.26, formatter dry-run su 49 file, Larastan livello 8 zero errori, 46 test Pest/125 asserzioni senza skip; lint/format/TypeScript, 5 test qualità, 3 test DOM su 2 file, build Vite riuscita. Installazione lock via npm ci con peer stretti superata, audit npm a zero advisory noti al momento della prova, `composer validate --strict --no-check-publish` e `sh -n scripts/verify.sh` superati.
- Prova negativa reale: vecchio comando dal container web con RUN_DOCKER_TESTS=1 rifiutato dalla guardia con zero asserzioni eseguite prima di connessione/migration. Questo fallimento intenzionale conferma la separazione dai servizi sviluppo, non è una suite ancora rossa.
- Host PHP 8.5.9: suite ordinaria 37 test/88 asserzioni superata, 9 integrazioni saltate come previsto senza collegamenti DB; test frontend e controllo tipi/lint passati anche sul Mac. Prova iniziale TypeScript ha richiesto di completare `rememberedState` nella fixture Inertia con la forma effettiva dell’adapter, senza cast o allentamento dei controlli.
- Limiti: verificati DOM simulato e tastiera, non una sessione browser end-to-end o audit accessibilità completo. CI PHP 8.4/8.5 e checkout pulito restano M0.07/criterio finale M0. Suite DB sequenziale, nessuna promessa di parallelismo o benchmark. Nessun comando Git/gh eseguito e nessun reset dei volumi/database di sviluppo.
- Completato M0.06; prossimo passo M0.07: GitHub Actions con matrice PHP 8.4/8.5 e gli stessi controlli/test su servizi dedicati. M0 complessiva resta aperta.

### 2026-10-16 — M0.07: GitHub Actions (completato)

- Dipendenze M0.01–M0.06 soddisfatte; M0.06 completato e mergiato su staging secondo conferma utente. Nessun comando Git/gh eseguito.
- Decisione esplicita utente: PHP 8.4 e frontend su tutte le PR e push staging/main; PHP 8.5 soltanto sui push main (incluso merge) e avvio manuale su main. Compatibilità 8.5 rilevata dopo il merge, per contenere i controlli delle PR.
- Piano: step distinti per manifest, installazione lock, piattaforma, formatter dry-run, Larastan 8 e Pest completo senza skip; frontend lint/format/tipi/test qualità/DOM/build. Servizi PostgreSQL 16 e Redis 7 isolati.
- Criteri completati: verifiche locali riportate sotto ed esiti verdi remoti confermati dall’utente, incluso PHP 8.5 su main. La conferma utente costituisce l’evidenza remota; l’agente non ha consultato log GitHub o eseguito Git/gh.
- File: nuovo `.github/workflows/ci.yml` con tre job indipendenti e step condivisi tramite anchor YAML; nuovo `docs/CI.md` con tabella eventi e tutti i check; aggiornati README, TESTING e questo piano. Nessuna modifica a manifest, lockfile o codice applicativo.
- Runtime CI: Ubuntu 24.04, PHP 8.4/8.5, Composer 2.10.2, Node 22.23.2 e npm 10.9.8; estensioni PHP esplicite e verifica piattaforma effettiva. Action checkout v7, setup-node v7, cache v5 e setup-php v2 fissate a SHA verificati sui metadati pubblici upstream, senza operazioni Git/gh. Servizi con digest riusati da Compose, fixture signalbox_test, health check e opt-in integrazioni; niente segreti o deploy.
- Controlli locali superati: actionlint 1.7.12 sul workflow (archivio ufficiale con checksum SHA-256 verificato); parsing YAML e risoluzione alias; otto scenari della condizione PHP 8.5 (PR, staging, main, dispatch e tag), verificati localmente senza simulare un'esecuzione GitHub. Primo tentativo actionlint con binario ARM non eseguibile sull'ambiente Intel; corretto scaricando amd64, poi validazione riuscita.
- Backend Docker PHP 8.4.26: `composer verify` superato, PHP-CS-Fixer dry-run su 49 file, Larastan livello 8 zero errori, 46 test Pest/125 asserzioni senza skip. Anche comando CI esatto `docker compose run --rm php-test composer test -- --fail-on-skipped` superato con gli stessi 46 test/125 asserzioni. `composer install --prefer-dist --no-interaction --no-progress` superato su vendor già presente, senza pacchetti da installare/aggiornare e con autoload/package discovery eseguiti: non equivale a installazione backend da checkout pulito.
- Composer host PHP 8.5.9: `composer validate --strict --no-check-publish` e `composer check-platform-reqs` superati; `composer check-platform-reqs` superato anche nel runner locale Docker PHP 8.4.26. Prova negativa `composer test -- --fail-on-skipped --compact` senza opt-in: 37 test/88 asserzioni passati e 9 integrazioni saltate, uscita 1 attesa; conferma che l'opzione CI non può produrre verde saltando i servizi. Non è una suite di regressione ancora rossa né una verifica completa delle integrazioni PHP 8.5.
- Frontend host Node 22.23.2/npm 10.9.8: `npm run verify` superato (lint/formato/tipi, 5 test qualità, 3 test DOM, build). Linux Docker con npm 10.9.9 già presente nell'immagine locale: `npm ci --ignore-scripts --strict-peer-deps` superato, 292 pacchetti installati, zero advisory riportati al momento; `npm run verify` superato anche dopo questa installazione pulita (lint/formato/tipi, 5 test qualità, 3 test DOM e build). La CI seleziona esplicitamente npm 10.9.8 come da piano; non è stata modificata l'immagine locale.
- Chiusura: l’utente conferma che la CI funziona, inclusa l’esecuzione PHP 8.5 su main dopo le indicazioni su merge/avvio manuale. M0.07 completato; nessun URL run o dettaglio di log remoto fornito, nessuna modifica branch protection o prova locale completa da checkout pulito dichiarata. Aggiornamento documentale senza rieseguire test: nessuna modifica al workflow o al codice. Prossimo task M0.08: decisioni architetturali su confini di dominio, checker Strategy, tenant context, policy limiti e convenzioni di documentazione. M0.09 e M0 complessiva restano aperte.

### 2026-10-16 — M0.08: decisioni architetturali (completato)

- Dipendenze M0.01–M0.07 soddisfatte; esiti CI confermati dall’utente, incluso PHP 8.5 su main. Branch docs/architecture-decisions gestito dall’utente; nessun comando Git/gh eseguito.
- Obiettivo: documentare confini del dominio, Strategy dei checker, tenant context, policy limiti e convenzioni di documentazione, distinguendo decisioni approvate dall’implementazione futura.
- Perimetro: documentazione e collegamenti; nessuna classe, migration, interfaccia vuota, dipendenza o funzionalità anticipata. Semantiche quantitative e affidabilità dettagliata restano M0.09.
- File: nuovo `docs/ARCHITECTURE.md` con ADR-001–ADR-005 (contesto, decisione, alternative, conseguenze e verifiche delle milestone proprietarie); collegamenti in README, BOOTSTRAP e registro decisioni di TASKS.
- Decisioni: monolite modulare con convenzioni Laravel e action per ambito; Strategy con adapter e heartbeat push separato; contesto tenant limitato all’unità di lavoro, scope con rifiuto in assenza di contesto e proiezioni pubbliche dedicate; autorizzazioni e limiti componibili con quota protetta da transazione/lock Team; documentazione inglese nel codice e record architetturali evolutivi. Nessuna funzionalità futura presentata come disponibile.
- Confini confermati: Free massimo cinque monitor; gli altri valori e le semantiche scheduler/recupero crash, retry/conferma regionale, retention/partizioni, SLA/percentili e riconciliazione restano M0.09. Nessuna riduzione di scope o modifica tacita alla specifica.
- Verifiche: controllo Python locale superato su quattro Markdown, 36 destinazioni di link locali esistenti, cinque sezioni ADR complete, presenza del limite Free e dei rinvii M0.09, newline finale e assenza di spazi finali. Revisione di coerenza con specifica, AGENTS e bootstrap effettivo.
- Formatter PHP, Larastan, TypeScript, lint/build e suite applicative non rieseguiti: modifiche esclusivamente Markdown, escluso dal formatter frontend esistente; nessun comportamento eseguibile aggiunto da coprire con nuovi test. La verifica documentale non certifica le implementazioni future.
- Completato M0.08; prossimo passo M0.09 per chiudere le decisioni fondanti residue prima di M1. M0 complessiva resta aperta, inclusi i criteri di uscita non ancora attestati; nessun comando Git/gh eseguito.
