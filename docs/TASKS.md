# Signalbox — Piano dei task

## Riferimenti e stato iniziale

Specifica: [03-Progetto-B-Signalbox-Laravel.md](03-Progetto-B-Signalbox-Laravel.md). Regole di lavoro: [AGENTS.md](../AGENTS.md). Dipendenze verificate e riferimento manifest: [DEPENDENCIES.md](DEPENDENCIES.md). Bootstrap e configurazioni iniziali: [BOOTSTRAP.md](BOOTSTRAP.md).

Alla pianificazione iniziale del 2026-10-15 era presente soltanto la specifica. M0.01 ha aggiunto manifest e lockfile; M0.02 ha inizializzato Git e lo scheletro Laravel/Inertia/React con avvio, test iniziali e build verificati. M0.03 aggiunge l’ambiente Docker locale, M0.04 configura i pacchetti e M0.05 completa i controlli locali di qualità; M0.06 introduce test DOM e servizi test isolati; M0.07 implementa la CI, con esiti remoti positivi confermati dall’utente anche per PHP 8.5 su main.

Stati: `[ ]` da fare, `[/]` in corso, `[x]` completato, `[!]` bloccato. Gli ID sono stabili: non rinumerarli. Per ogni task in corso o bloccato aggiungere una nota nel registro con dipendenze, evidenza e prossimo passo. Le checklist di milestone sono il riferimento operativo, non attestano implementazioni già presenti.

Le ore sono quelle delle singole righe della specifica: **178 ore complessive**, mentre il totale dichiarato è ~158 ore e l'introduzione indica 130–150 ore. Ristimare dopo M0, includendo documentazione capillare e controlli richiesti; non assumere 8–9 settimane come scadenza confermata.

## Consegna di sessione — M4.02–M4.03, 2026-10-28

- **M4.02 e M4.03 completati** (uniti su decisione dell’utente): sequenze di fallimento per regione nella transazione del risultato, verdetto congiunto per slot, apertura/riapertura/risoluzione automatica con voci di sistema ed eventi dopo commit, azioni manuali (presa in carico, aggiornamenti e risoluzione, severità, causa dichiarata, pubblicazione) con `IncidentPolicy`. Dettagli in [INCIDENTS.md](INCIDENTS.md#regional-confirmation).
- `./scripts/verify.sh` **exit 0**, PHP **8.4.27**: PHP-CS-Fixer dry-run su **361 file**, Larastan livello 8, **1551 test Pest/9339 asserzioni** (0 skip), lint/formato/TypeScript, **5 test qualità**, **53 test DOM** e build Vite. Log nello scratchpad della sessione (`m402-verify.log`).
- Nuova migration `2026_10_28_000014_create_monitor_region_sequences.php`, applicata soltanto al database di test: **applicarla deliberatamente al database di sviluppo dopo quella degli incidenti**. Da quel momento ogni risultato registrato può aprire, risolvere o riaprire incidenti. Nessuna rotta, UI, dipendenza o modifica `.env`; Git/gh non eseguiti. CI remota/PHP 8.5 non eseguite.
- Prossimo task pronto: **M4.04**, finestre di manutenzione e tracciamento delle pause per lo SLA.

## Consegna di sessione — M4.01, 2026-10-28

- **M4.01 completato**: tabelle `incidents`/`incident_updates`, enum `IncidentStatus`/`IncidentSeverity`, modelli tenant-scoped, factory, `config/incidents.php` (`reopen_window_seconds` = 900). Un solo incidente aperto per monitor (indice unico parziale), timeline append-only con pubblicazione una tantum (trigger + modello), transizioni e riaperture contate verificate anche dal modello. Dettagli in [INCIDENTS.md](INCIDENTS.md).
- `./scripts/verify.sh` **exit 0**, PHP **8.4.27**: PHP-CS-Fixer dry-run su **340 file**, Larastan livello 8, **1497 test Pest/9134 asserzioni** (0 skip), lint/formato/TypeScript, **5 test qualità**, **53 test DOM** e build Vite. Log nello scratchpad della sessione (`m401-verify.log`).
- Nuova migration `2026_10_28_000013_create_incidents_tables.php`, applicata soltanto al database di test: **applicarla deliberatamente al database di sviluppo**. Nessuna rotta, job, UI, dipendenza o modifica `.env`; Git/gh non eseguiti. CI remota/PHP 8.5 non eseguite.
- Prossimo task pronto: **M4.02**, fallimenti consecutivi per regione, soglia e conferma dalla seconda regione.

## Consegna di sessione — M3.08, 2026-10-26

- **M3.08 completato, M3 completata**: matrice integrata con **21 nuovi casi**: sequenze reali fino alla persistenza, errori e ripristini, heartbeat ai confini temporali, pause/modifiche, worker persistenti tra tenant, regioni indipendenti e lock Redis fra processi. Matrice completa in [TESTING.md](TESTING.md#integrated-engine-acceptance-m308).
- `./scripts/verify.sh` **exit 0**, PHP **8.4.27**: formatter su **328 file**, Larastan livello 8, **1411 test Pest/8991 asserzioni**, lint/formato/TypeScript, **5 test qualità**, **53 test DOM** e build Vite. Log: `/tmp/signalbox-m308-verify.log`. Suite backend finale senza asset Vite, con `--fail-on-skipped`, **exit 0**: **1411 test/8991 asserzioni**, nessun test saltato. Log: `/tmp/signalbox-m308-no-assets.log`.
- Modificati soltanto test, fixture e documentazione. Nessuna nuova API, migration, dipendenza o modifica ai lockfile; nessuna modifica a `.env`, chiavi, database o processi di sviluppo. Motore ancora disabilitato per default e Git/gh non eseguiti. Nessuna CI remota, PHP 8.5 integrata, browser E2E o benchmark dichiarati.
- Prossimo task pronto: **M4.01**, modello incidenti e transizioni. M4/M5 e retention M7 restano nei rispettivi task; soglia di latenza e MA.05/MA.06 restano rinviati.

## Consegna di sessione — M3.06–M3.07, 2026-10-26

- **M3.06–M3.07 completati**: runner reale per tutti i checker, lotti regionali di due monitor, lock condivisi e fence del broker implementati; risultati immutabili e riepiloghi atomici su partizioni mensili UTC. Dettagli in [MONITOR-RUNNER.md](MONITOR-RUNNER.md).
- Prima verifica completa `./scripts/verify.sh` **exit 0**: PHP 8.4.27, formatter su 325 file, Larastan livello 8, 1388 test Pest/8542 asserzioni, lint/formato/TypeScript, 5 test qualità, 53 test DOM e build Vite. Verifica finale `docker compose run --rm -v /tmp/signalbox-heartbeat-no-assets-m8y9j5v1:/var/www/html/public/build:ro php-test composer verify` **exit 0**, con le ultime modifiche: formatter su 325 file, Larastan e **1390 test Pest/8550 asserzioni** superati senza asset Vite. Log: `/tmp/signalbox-runner-verify.log` e `/tmp/signalbox-runner-no-assets.log`.
- Migration `2026_10_26_000012_create_partitioned_check_results.php` applicata soltanto al database di test. Nessuna modifica a `.env`, chiavi, dipendenze, lockfile, database o processi di sviluppo; nessuna operazione Git/gh. Consumer associato, motore ancora disabilitato per default. Nessuna nuova CI remota/PHP 8.5 integrata, browser E2E o benchmark dichiarati.
- Prossimo task pronto: **M3.08**; incidenti M4 e retention M7 restano successivi. MA.05/MA.06 e soglia di latenza restano rinviati.

## Correzione HeartbeatTest — 2026-10-26

- Riprodotto e corretto un 409 sul primo test in assenza di manifest Vite, condizione del backend CI. L’header versione ora usa una stringa vuota quando il middleware restituisce null.
- HeartbeatTest: **24 test/121 asserzioni** superati sia con build disponibile sia senza build; formatter dry-run su 312 file passato. Aggiunto un caso permanente senza manifest tramite directory pubblica temporanea.
- Modificati soltanto test e documentazione. CI remota da confermare dopo il nuovo push; il log remoto non è stato fornito. Le verifiche complete M3.05 riportate sotto restano quelle della consegna precedente.

## Consegna di sessione — M3.05, 2026-10-26

- **M3.05 completato**: ingresso `POST /heartbeats` con Bearer dedicato, scadenza mobile e tolleranza, snapshot storici allo slot programmato e UI di generazione/rotazione/revoca con segreto una sola volta. Dettagli in [HEARTBEATS.md](HEARTBEATS.md).
- `./scripts/verify.sh` **exit 0**, PHP **8.4.27**: PHP-CS-Fixer su **312 file**, Larastan livello 8, **1359 test Pest/8387 asserzioni**, lint/formato/TypeScript, **5 test qualità**, **53 test DOM** e build Vite. Log: `/tmp/signalbox-m3-05-verify.log`.
- Nuova migration `2026_10_26_000011_create_monitor_heartbeats.php`, applicata soltanto al database di test. **Applicarla deliberatamente al database di sviluppo prima di usare il CRUD aggiornato o gli heartbeat**; gli heartbeat preesistenti vengono inizializzati senza credenziale all’istante della migration.
- Nessuna modifica a `.env`, chiavi, dipendenze o processi di sviluppo. Scheduler disabilitato e consumer applicativo non associato. Nessuna nuova CI remota/PHP 8.5 integrata, browser E2E o benchmark.
- Prossimo task pronto: **M3.06**, runner, middleware job, timeout/failure hook e integrazione delle code regionali; persistenza partizionata in M3.07. MA.05/MA.06 e soglia di latenza restano rinviati. Git/gh riservati all’utente.

## Consegna di sessione — M3.04, 2026-10-26

- **M3.04 completato**: checker TLS/DNS concorrenti con adapter sostituibili, pinning/verifica TLS, confronto DNS esatto e risoluzione del guard HTTP con timeout. Dettagli in [TLS-DNS-CHECKERS.md](TLS-DNS-CHECKERS.md).
- `./scripts/verify.sh` **exit 0**: PHP **8.4.27**, PHP-CS-Fixer su **293 file**, Larastan livello 8, **1289 test Pest/7926 asserzioni**, lint/formato/TypeScript, **5 test qualità**, **46 test DOM** e build Vite. Log: `/tmp/signalbox-m3-04-verify.log`.
- Confermate le scelte utente: insieme DNS esatto, resolver di sistema come default fidato, down nella finestra di preavviso TLS. Dipendenze ReactPHP già installate dichiarate dirette, nessuna versione aggiornata; OpenSSL esplicito.
- Scheduler disabilitato e consumer non associato. Nessuna migration, modifica `.env`/chiavi o gestione dei processi di sviluppo. Nessuna nuova CI remota/PHP 8.5 integrata, prova nel browser o benchmark.
- Prossimo task pronto: **M3.05**, heartbeat con endpoint, credenziale dedicata, scadenza e tolleranza. MA.05/MA.06 e soglia di latenza restano rinviati; nessuna riduzione di M3/M4/M5. Git/gh restano riservati all’utente.

## Chiusura di sessione e ripartenza — 2026-10-23

- Sessione chiusa su richiesta dell’utente. Completati M3.01–M3.03; M3 resta aperta. Le consegne successive descrivono lo stato storico dei singoli interventi.
- Push/GitHub: dopo la segnalazione del banner assente, l’utente conferma «tutto a posto». Nessuna verifica Git/gh o remota eseguita dall’agente; non sono stati forniti log, branch, SHA o risultati specifici dei job CI, quindi non si aggiunge una dichiarazione di CI verde/PHP 8.5.
- Ultima verifica locale completa: `./scripts/verify.sh` exit 0, PHP 8.4.27, formatter su 272 file, Larastan livello 8, 1220 test Pest/7728 asserzioni, lint/formato/TypeScript, 5 test qualità, 46 test DOM e build. Questa chiusura modifica solo TASKS; nessuna nuova esecuzione dei controlli, perché il codice non cambia.
- Prossimo task pronto: **M3.04**, strategie TLS (scadenza, catena, hostname) e DNS (A/AAAA/CNAME/MX/TXT e resolver), con adapter sostituibili. Affrontare il limite noto della risoluzione DNS usata dal guard HTTP, oggi senza timeout applicativo.
- Per ripartire leggere AGENTS, specifica, TASKS, DEPENDENCIES, BOOTSTRAP, ARCHITECTURE (ADR-002/006/007), OUTBOUND-SECURITY, HTTP-CHECKERS, MONITOR-SCHEDULING e TESTING; lavorare nel progetto principale `/Users/pino/Projects/signalbox`.
- Motore disabilitato e consumer applicativo non associato fino al runner reale. Nessuna migration, modifica `.env`/chiavi o gestione dei processi di sviluppo in questa sessione. La migration M3.01–M3.02 non è stata applicata dall’agente al database di sviluppo; stato di eventuali interventi dell’utente non verificato.
- Decisioni da conservare: soglia di latenza rinviata per scelta esplicita dell’utente; MA.05/MA.06 rinviati; nessuna riduzione di M3/M4/M5. Git e GitHub CLI restano riservati all’utente.
- Preferenza di consegna dell’utente: al termine di ogni task proporre un messaggio di commit composto da **una breve frase**, senza corpo descrittivo.

## Consegna di sessione — M3.03, 2026-10-23

- Completato M3.03: strategie HTTP/keyword, esiti normalizzati, acquisizione con `Http::pool()`, protezioni SSRF/redirect, budget di rete cumulativi e cap di 1 MiB decompressi. Dettagli in [HTTP-CHECKERS.md](HTTP-CHECKERS.md) e nel registro finale.
- `./scripts/verify.sh` exit 0: PHP 8.4.27, 272 file PHP verificati, Larastan livello 8, 1220 test Pest/7728 asserzioni, controlli frontend, 5 test qualità, 46 test DOM e build.
- Motore ancora disabilitato e consumer applicativo non associato. Nessuna nuova migration, modifica `.env`, dipendenza o operazione Git/gh. La migration M3.01–M3.02 resta non applicata dall’agente al database di sviluppo, come documentato nella consegna precedente.
- Decisione utente: misurazione della latenza senza aggiungere ora la soglia della specifica. DNS conserva il limite noto senza timeout applicativo.
- Prossimo task funzionale: **M3.04**, TLS/DNS e adapter. MA.05/MA.06 rinviati. Nessuna nuova verifica CI remota/PHP 8.5.

## Consegna di sessione — M3.01–M3.02, 2026-10-23

- Completati insieme M3.01 e M3.02: slot UTC/jitter e intervalli di 30 secondi, prenotazione transazionale, outbox regionale, publisher recuperabile, acquisizione unica e fencing. Dettagli in [MONITOR-SCHEDULING.md](MONITOR-SCHEDULING.md) e nel registro finale.
- `./scripts/verify.sh` exit 0: PHP 8.4.27, 248 file PHP verificati, Larastan livello 8, 1158 test Pest/7444 asserzioni, controlli frontend, 5 test qualità, 46 test DOM e build.
- Flusso disabilitato per default fino al runner reale. Migration nuova presente ma non applicata al database di sviluppo; va applicata prima dell’uso del CRUD aggiornato. `.env`, chiavi e processi applicativi non modificati.
- Prossimo task funzionale: **M3.03**. MA.05/MA.06 rinviati su richiesta dell’utente. Nessuna operazione Git/gh e nessuna nuova verifica CI remota/PHP 8.5.
- Le consegne seguenti conservano lo stato storico delle sessioni precedenti.

## Consegna di sessione e ripartenza — 2026-10-23

- Sessione chiusa su richiesta dell'utente. Completati: M2.05, M2.06 (con correzione CI), milestone MA.01–MA.04 (operatori di piattaforma e override di piano, fuori specifica), pgAdmin opzionale su loopback e aggiornamento di sicurezza a PHP 8.4.27.
- CI remota: dopo il push dell'utente su un nuovo branch con nuova PR verso staging, l'utente conferma la CI verde. La patch PHP usata dal runner della CI non è stata letta dall'agente; i log del job la riportano.
- Database di sviluppo: l'utente ha applicato le migration fino a MA e ha impostato `is_platform_admin = true` sul proprio account direttamente nel database, senza il comando, quindi senza evento di audit. Verificati in sola lettura un operatore verificato e 0 eventi di audit. In produzione usare solo `signalbox:platform-admin`.
- Container di sviluppo `web`, `queue` e `scheduler` in esecuzione su PHP 8.4.27; pgAdmin non avviato (si attiva con il profilo `dbadmin` e le credenziali nel `.env`).
- Prossimo task funzionale: **M3.01** (`monitors:dispatch`, jitter deterministico, selezione chunkById e avanzamento senza deriva). MA.05/MA.06 restano da pianificare quando l'utente lo chiederà.

## Consegna di sessione e ripartenza — 2026-10-22

- M0 completata il 2026-10-19: decisioni fondanti e prova finale da checkout pulito concluse. Gli esiti dettagliati e i limiti restano nel registro; le note precedenti descrivono lo stato storico delle rispettive sessioni.
- M1.01 completato il 2026-10-20: utenti, team, membership, ruoli owner/admin/member/viewer, timezone e team corrente; migration, factory, seeder e test PostgreSQL. Dettagli e limiti in [IDENTITY.md](IDENTITY.md) e nel registro di sessione.
- M1.02 completato il 2026-10-20: registrazione con primo team, login/logout, recupero password, verifica email obbligatoria e 2FA facoltativa con UI inglese. Dettagli in [AUTHENTICATION.md](AUTHENTICATION.md). M1.03 completato il 2026-10-21: inviti email, accettazione, gestione membri/cambio team e proprietà; dettagli in [TEAMS.md](TEAMS.md). M1.04 completato il 2026-10-22: contesto tenant, TeamScope sugli inviti privati e policy team/inviti; dettagli in [TENANCY.md](TENANCY.md). M1.05 completato il 2026-10-22: matrice parametrica degli ingressi disponibili, documentata in [AUTHORIZATION-MATRIX.md](AUTHORIZATION-MATRIX.md). M1 completata. M2.01 completato il 2026-10-22: modello Monitor e configurazioni tipizzate, documentati in [MONITORS.md](MONITORS.md). M2.02 completato il 2026-10-22: Form Request e validazione per tipo, documentati in [MONITOR-INPUT.md](MONITOR-INPUT.md). M2.03 completato il 2026-10-22: confine SSRF condiviso, pinning DNS, redirect e redazione segreti, documentati in [OUTBOUND-SECURITY.md](OUTBOUND-SECURITY.md). M2.04 completato il 2026-10-22: CRUD, pausa/ripresa, MonitorPolicy e limiti di piano centralizzati sotto lock, documentati in [MONITOR-CRUD.md](MONITOR-CRUD.md). M2.05 completato il 2026-10-23: pagine lista/dettaglio/form, ricerca Scout sulle colonne in chiaro, filtri e paginazione accessibili, documentati in [MONITOR-UI.md](MONITOR-UI.md). MA.01–MA.04 completati il 2026-10-23 (operatori di piattaforma da CLI e pannello `/admin` per override illimitato o piano omaggio, fuori specifica: [PLATFORM-ADMIN.md](PLATFORM-ADMIN.md)). M2.06 completato il 2026-10-23: copertura integrata di tutti i tipi, input errati, permessi, isolamento e sesto monitor Free rifiutato con richieste concorrenti al server HTTP reale; M2 completata. Prossimo task pronto: **M3.01**, `monitors:dispatch` con jitter deterministico, selezione chunkById e avanzamento senza deriva. Leggere AGENTS, specifica, questo registro, MONITORS/MONITOR-CRUD/MONITOR-UI, ARCHITECTURE (scheduling, ADR pertinenti) e TESTING.
- Lavorare nel progetto principale `/Users/pino/Projects/signalbox`, non nel checkout temporaneo della prova. Nessuna operazione Git/gh dell'agente; branch e commit restano all'utente.
- Ultima verifica completa (aggiornamento PHP 8.4.27): `./scripts/verify.sh` exit 0, PHP 8.4.27, formatter su 226 file, Larastan livello 8, 1111 test Pest/7228 asserzioni, lint/formato/TypeScript, 5 test qualità, 46 test DOM e build. Nessuna nuova CI remota/PHP 8.5 integrata dichiarata.
- Baseline storica MA: `./scripts/verify.sh` exit 0, PHP 8.4.26, formatter su 226 file, Larastan livello 8, 1111 test Pest/7228 asserzioni (anche senza build Vite, come in CI), lint/formato/TypeScript, 5 test qualità, 46 test DOM e build. Nessuna nuova CI remota/PHP 8.5 integrata dichiarata.
- Baseline storica M2.06: `./scripts/verify.sh` exit 0, PHP 8.4.26, formatter su 202 file, Larastan livello 8, 1056 test Pest/6924 asserzioni, lint/formato/TypeScript, 5 test qualità, 40 test DOM e build. Nessuna nuova CI remota/PHP 8.5 integrata dichiarata.
- Baseline storica M2.05: `./scripts/verify.sh` exit 0, PHP 8.4.26, formatter su 198 file, Larastan livello 8, 1014 test Pest/6220 asserzioni, lint/formato/TypeScript, 5 test qualità, 31 test DOM e build. Nessuna nuova CI remota/PHP 8.5 integrata dichiarata.
- Baseline storica M2.04: `./scripts/verify.sh` exit 0, PHP 8.4.26, formatter su 189 file, Larastan livello 8, 922 test Pest/4974 asserzioni, lint/formato/TypeScript, 5 test qualità, 20 test DOM e build. Nessuna nuova CI remota/PHP 8.5 integrata dichiarata.
- Baseline storica M2.03: `./scripts/verify.sh` exit 0, PHP 8.4.26, formatter su 179 file, Larastan livello 8, 806 test Pest/4018 asserzioni, lint/formato/TypeScript, 5 test qualità, 20 test DOM e build. Nessuna nuova CI remota/PHP 8.5 integrata dichiarata.
- Baseline storica M2.02: `./scripts/verify.sh` exit 0, PHP 8.4.26, formatter su 159 file, Larastan livello 8, 680 test Pest/3796 asserzioni, lint/formato/TypeScript, 5 test qualità, 20 test DOM e build. Nessuna nuova CI remota/PHP 8.5 integrata dichiarata.
- Baseline storica M2.01: `./scripts/verify.sh` exit 0, PHP 8.4.26, formatter su 143 file, Larastan livello 8, 538 test Pest/3503 asserzioni, lint/formato/TypeScript, 5 test qualità, 20 test DOM e build. Nessuna nuova CI remota/PHP 8.5 integrata dichiarata.
- Baseline storica M1.05: `./scripts/verify.sh` exit 0 con PHP 8.4.26, formatter su 122 file, Larastan livello 8, 449 test Pest/3163 asserzioni, lint/formato/TypeScript, 5 test qualità, 20 test DOM e build. Nessuna nuova CI remota o integrazione completa PHP 8.5 dichiarata.
- Baseline storica M1.04: `./scripts/verify.sh` superato con PHP 8.4.26, formatter su 119 file, Larastan livello 8, 195 test Pest/1039 asserzioni, lint/formato/TypeScript, 5 test qualità, 20 test DOM e build. Dettagli nel registro; nessuna nuova CI remota o integrazione PHP 8.5 dichiarata.
- Baseline storica M1.03: `./scripts/verify.sh` superato con PHP 8.4.26, PHP-CS-Fixer su 106 file, Larastan livello 8, 178 test Pest/924 asserzioni, lint/formato/TypeScript, 5 test qualità, 20 test DOM e build. Suite finale anche senza asset/.env di sviluppo con zero skip e warning; dettagli nel registro. Nessuna nuova CI remota o integrazione completa PHP 8.5 dichiarata.
- Ambiente applicativo principale su `http://127.0.0.1:8000`; servizi test isolati usati e lasciati attivi. Nessun riavvio/reset applicativo o migration sul database di sviluppo; applicare esplicitamente la migration inviti prima della prova manuale. L'ambiente temporaneo M0 su porta 18090 non è stato usato o gestito.

## Criterio comune di completamento

Correzione CI backend del 2026-10-21 verificata localmente e conservata nell’implementazione e nella revisione M1.03 completate successivamente. Conferma GitHub dopo push dell’utente ancora pendente; prossimo task funzionale M3.01 dopo il completamento locale di M2. Vedere il registro delle sessioni.

Per ogni task: comportamento verificato, autorizzazioni e isolamento rispettati, documentazione in inglese per tutti gli elementi del codice come da AGENTS, test automatici pertinenti superati, PHP-CS-Fixer e controlli statici applicabili superati, piano aggiornato. Non completare una milestone prima dei suoi criteri di uscita. Annotare comandi e risultati effettivi nel registro di sessione.

## M0 — Setup e decisioni fondanti (8 h della specifica, completata)

Dipendenze: nessuna. Sblocca tutte le milestone.

- [x] M0.01 Verificare matrice ufficiale PHP/Laravel/Inertia/React/Pest/Cashier e disponibilità JSON:API; fissare versioni e lockfile, registrando eventuali incompatibilità.
- [x] M0.02 Inizializzare Git e Laravel 13 con Inertia 2, React 19, TypeScript e Tailwind 4; configurare variabili di ambiente di esempio senza segreti.
- [x] M0.03 Preparare Docker/Sail con PHP 8.4, PostgreSQL 16, Redis 7 e processi web, queue, scheduler e Reverb; documentare avvio e reset locale.
- [x] M0.04 Installare e configurare Horizon, Reverb, Sanctum, Scout database driver e strumenti test; predisporre Cashier in modalità Stripe test.
- [x] M0.05 Configurare PHP-CS-Fixer come formatter PHP di riferimento, Larastan livello 8, valutazione Rector, lint/format frontend e TypeScript rigoroso; documentare la sostituzione di Pint. Baseline PHP di M0.02 confermata; completati lint/format frontend, controllo TypeScript, test degli strumenti e valutazione Rector.
- [x] M0.06 Configurare Pest e test frontend, database di test PostgreSQL, factory iniziali e comandi unificati di verifica. Completati suite Unit/Feature/Integration, test DOM, servizi test effimeri separati, verifica factory User e comando completo.
- [x] M0.07 Creare GitHub Actions con PHP 8.4/8.5, PostgreSQL/Redis, formatter dry-run, analisi, test e build frontend.
- [x] M0.08 Registrare decisioni architetturali su confini di dominio, checker Strategy, tenant context, policy limiti e convenzioni di documentazione.
- [x] M0.09 Definire semantica scheduler sotto-minuto, prenotazione/invio affidabile, retention/partizioni, SLA e p50/p95; definire matrice dei piani. Decisioni adottate in ADR-006–ADR-010, implementazione nelle milestone proprietarie.

Uscita soddisfatta il 2026-10-19: bootstrap e smoke test da checkout pulito con PHP 8.4 superati; esiti CI PHP 8.4/8.5 già confermati dall’utente in M0.07. Dettagli e limiti nel registro della verifica finale. Alla chiusura M0 il primo task pronto era M1.01, completato il 2026-10-20; anche M1.02–M1.05 e M2.01–M2.06 sono ora completati: procedere con M3.01.

## M1 — Autenticazione e tenancy (14 h della specifica, completata)

Dipendenze: M0.

- [x] M1.01 Creare utenti, team, membership, ruoli, timezone e team corrente con migration, factory e seeder.
- [x] M1.02 Implementare registrazione, login/logout, recupero password, verifica email e 2FA con UI.
- [x] M1.03 Implementare inviti email, accettazione, gestione membri e cambio team; definire scadenza inviti e vincoli sull'ultimo owner.
- [x] M1.04 Implementare TeamScope, tenant context esplicito nei processi asincroni e policy per owner/admin/member/viewer.
- [x] M1.05 Testare accessi diretti con ID di altri team, cambio team, inviti, ruoli e isolamento parametrico delle rotte disponibili; estendere la matrice a ogni milestone.

Uscita soddisfatta il 2026-10-22: nessun accesso tra tenant nei percorsi testati, inclusi job e scope; autenticazione, quattro ruoli e matrice delle rotte attuali verificati. M1.05 aggiunge 254 casi, suite complessiva 449 test/3163 asserzioni; dettagli, copertura e limiti nel registro e in AUTHORIZATION-MATRIX. Estendere i test alle nuove risorse/ingressi di ogni milestone.

## M2 — CRUD monitor (14 h)

Dipendenze: M1; matrice piani e contratti checker di M0.

- [x] M2.01 Modellare Monitor con enum, configurazione tipizzata per HTTP/TLS/DNS/heartbeat/keyword, regioni, pause e campi di scheduling.
- [x] M2.02 Creare Form Request e validazione per tipo: URL, metodo/header/body, status/keyword, DNS/resolver, TLS, intervalli, timeout e soglie.
- [x] M2.03 Implementare protezioni SSRF per destinazioni, redirect, resolver e indirizzi risolti; definire gestione dei segreti e redazione degli output.
- [x] M2.04 Implementare CRUD, pause/ripresa, policy e policy centralizzata dei limiti iniziali.
- [x] M2.05 Creare UI lista/dettaglio/form, ricerca Scout, filtri, paginazione e stati vuoti/errore con accessibilità.
- [x] M2.06 Testare tutti i tipi, input errati, permessi e isolamento; verificare che il sesto monitor Free sia rifiutato via UI anche con richieste concorrenti.

Uscita: tutti i tipi sono configurabili e persistiti correttamente; limiti applicati dal backend.

Uscita soddisfatta il 2026-10-23: i cinque tipi sono creati, proiettati, modificati, sospesi, ripresi ed eliminati tramite le rotte di produzione con i payload del form React (contratto condiviso); input errati, permessi per tipo e isolamento sono verificati; il sesto monitor Free è rifiutato dal backend anche con richieste concorrenti al server HTTP reale. Dettagli in [MONITOR-UI.md](MONITOR-UI.md#integrated-coverage-m206). M2 completata.

## MA — Amministrazione piattaforma (fuori specifica, decisione di scope del 2026-10-23)

Dipendenze: M1, M2. Aggiunta su richiesta dell'utente per consentire prove senza vincoli di piano e piani concessi senza sottoscrizione; non riduce né sostituisce M3–M12. Dettagli in [PLATFORM-ADMIN.md](PLATFORM-ADMIN.md).

- [x] MA.01 Ruolo super admin su database concesso solo da CLI, gate di piattaforma (anche Horizon), 404 ai non admin, 2FA obbligatoria in produzione, audit.
- [x] MA.02 Override di entitlement per team: test illimitato (profilo interno con tetti finiti) e piano omaggio Pro/Business con scadenza opzionale, sotto lock Team.
- [x] MA.03 Pannello team: elenco con ricerca, dettaglio con entitlement effettivo, form override e storico di audit.
- [x] MA.04 Testare gate, CLI, precedenza, effetti sui limiti, concorrenza, matrice delle rotte e UI.
- [ ] MA.05 (futuro, da pianificare) Gestione utenti dal pannello.
- [ ] MA.06 (futuro, da pianificare) Impersonificazione con audit, durata limitata e mai su altri admin.

Uscita: un team di test può operare senza limiti di piano e un cliente può ricevere un piano senza sottoscrizione, con ogni modifica autorizzata e tracciata.

Uscita soddisfatta il 2026-10-23 per MA.01–MA.04; MA.05/MA.06 restano da pianificare.

## M3 — Motore di check (18 h, nucleo obbligatorio)

Dipendenze: M2.

- [x] M3.01 Implementare `monitors:dispatch`, jitter deterministico, selezione chunkById e avanzamento temporale senza deriva; rendere effettivi gli intervalli sotto il minuto se ammessi dai piani.
- [x] M3.02 Garantire dispatch concorrente sicuro con prenotazione transazionale, lock distribuiti e strategia di recupero dal crash tra commit e invio; testare perdita e duplicazione dei job.
- [x] M3.03 Implementare contratto e strategie HTTP e keyword con `Http::pool()`, redirect controllati, timeout e classificazione degli errori.
- [x] M3.04 Implementare strategie TLS (scadenza, catena, hostname) e DNS (A/AAAA/CNAME/MX/TXT e resolver) con adapter sostituibili nei test.
- [x] M3.05 Implementare heartbeat: endpoint con credenziale dedicata, scadenza, tolleranza e rilevamento delle chiamate mancanti.
- [x] M3.06 Configurare job e code `checks:eu`/`checks:us`, WithoutOverlapping, timeout, `tries = 1`, `failed()` e idempotenza della persistenza; documentare eventuali retry di dominio con backoff.
- [x] M3.07 Creare CheckResult con partizionamento mensile PostgreSQL, indici e gestione anticipata delle nuove partizioni.
- [x] M3.08 Testare sequenze `Http::fake()`, endpoint lenti, errori TLS/DNS, heartbeat scaduti, pause, concorrenza, jitter e isolamento dei worker.

Uscita: controlli funzionanti per tutti i tipi, errori registrati, carico distribuito e nessun fallimento nascosto da retry infrastrutturali.

Uscita soddisfatta il 2026-10-26 con M3.08: tutti i tipi attraversano il motore fino ai risultati, errori osservati distinti dall’infrastruttura, tentativi contati, jitter deterministico e isolamento tenant/regionale verificati. Suite completa e backend senza asset: 1411 test/8991 asserzioni; nessun benchmark di throughput dichiarato. Motore di sviluppo ancora disabilitato. Prossimo task M4.01.

## FX — Correzioni dalla revisione pre-M4 (2026-10-27)

Dipendenze: M3. Da completare prima di M4.01, perché M4 consumerà risultati e transizioni del motore. Evidenze da analisi statica; Docker non attivo durante la revisione, nessuna riproduzione eseguita.

- [x] FX.01 `ResultPartitions::ensure()` esegue `SET LOCAL lock_timeout = '2s'` anche quando è annidato (da `ExecutionLedger::acquire()` dentro la transazione di `RunMonitorChecks::consumeBatch()` e da `finish()` nel ramo di errore dello snapshot): il savepoint rilasciato lascia il limite attivo fino al commit esterno, quindi i lock successivi Team/Monitor/esecuzione/heartbeat falliscono dopo 2 s di contesa e il lotto diventa errore infrastrutturale. Correzione: percorso rapido senza lock se la partizione esiste (memo per processo dei mesi verificati), creazione DDL consentita solo a `transactionLevel() === 0`, nessuna `SET LOCAL` nel percorso annidato. Test PostgreSQL: lock del team tenuto oltre 2 s da un secondo processo durante l’acquisizione di un lotto, che deve attendere e completare.
- [x] FX.02 La connessione `pgsql` non fissa il fuso di sessione: scritture senza offset (formato data dei modelli, `update(['next_check_at' => $next])` in `ReserveMonitorChecks`) sono corrette solo se il server PostgreSQL è in UTC, come le immagini Docker attuali. Su un database gestito con altro fuso slot, scadenze e trial sarebbero spostati. Correzione: `'timezone' => 'UTC'` in `config/database.php` con documentazione; test che verifica `SHOW TimeZone` e il round-trip di un istante dopo aver impostato un fuso diverso come default del ruolo/sessione e riconnesso.
- [x] FX.03 `ReserveMonitorChecks`, `PublishMonitorChecks` e `RecoverMonitorChecks` non isolano gli errori per elemento: un’eccezione persistente su un monitor con ID basso interrompe ogni esecuzione al secondo e blocca la prenotazione di tutti i monitor successivi, anche di altri tenant. Correzione: try/catch per elemento con log dei soli identificativi, conteggio degli errori, prosecuzione del ciclo ed esito non zero del comando quando ci sono errori. Test con un elemento che fallisce sempre e altri tenant comunque serviti.
- [x] FX.04 `ExecutionResults::summarize()` azzera `monitors.ssl_expires_at` quando lo slot più recente di un monitor TLS non contiene una scadenza osservata (missed, cancelled, infrastruttura, errore di handshake), contro la semantica “last observed”. Correzione: conservare il valore precedente salvo nuova osservazione o cambio configurazione (già azzerato da `ManageMonitors`). Test sui quattro esiti.

Note da portare in M4/M12, senza modifica ora: `last_status` resta `unknown` fra il primo e il secondo esito regionale dello stesso slot, quindi M4 deve valutare per regione e slot (ADR-007) e non derivare transizioni da `last_status`; ogni transizione del registro prende `FOR UPDATE` sulla riga del team, collo di bottiglia probabile per il benchmark M12.04 con un solo tenant grande.

## M4 — Incidenti e manutenzioni (14 h, nucleo obbligatorio)

Dipendenze: M3.

- [x] M4.01 Modellare incidenti, aggiornamenti, responsabilità, severità, causa e transizioni consentite.
- [x] M4.02 Implementare fallimenti consecutivi, soglia configurabile e conferma da seconda regione simulata; definire comportamento con una sola regione.
- [x] M4.03 Implementare apertura/chiusura automatica, presa in carico, aggiornamenti timeline e regole di riapertura con transazioni e vincoli contro duplicati.
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
- [ ] M7.04 Implementare job notturno, creazione/drop partizioni, retention per piano e verifica dei rollup prima della rimozione; rispettare la politica concordata sui 30 giorni. Integrare la pulizia delle ricezioni heartbeat introdotte in M3.05 preservando quelle necessarie agli slot ancora validi.
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
- [ ] M9.04 Completare policy centrale per monitor, frequenza minima, status page e retention; applicarla a tutte le mutazioni e ai job. Nota MA: gli override amministrativi attivi prevalgono sullo stato Stripe secondo la precedenza di [PLATFORM-ADMIN.md](PLATFORM-ADMIN.md).
- [ ] M9.05 Implementare sospensione deterministica dei monitor eccedenti al downgrade/scadenza e comportamento di successivo upgrade. Nota MA: includere la scadenza o revoca di un override. Nota M3: mantenere `execution_revision` per le mutazioni operative, così i lavori già prenotati vengono rivalutati; riusare HeartbeatLifecycle per iniziare una nuova generazione nelle future riprese heartbeat.
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

Compatibilità e JSON:API verificati in M0.01 (vedere DEPENDENCIES.md). Decisioni fondanti completate in M0.08–M0.09: ADR-001–ADR-010 in [ARCHITECTURE.md](ARCHITECTURE.md). M0 completata il 2026-10-19 con bootstrap e smoke test da checkout pulito; gli esiti CI PHP 8.4/8.5 restano quelli confermati dall’utente in M0.07, senza nuova esecuzione remota dichiarata. M1.01 e M1.02 completati il 2026-10-20; M1.03 completato il 2026-10-21. M3 è completata; M4.01–M4.03 completati il 2026-10-28, prossimo task pronto M4.04. La specifica cita un documento 02 non presente: non inventarne i requisiti; preparare README secondo le indicazioni disponibili.

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
| 2026-10-19 | ADR-006–ADR-010 adottati in M0.09 | [ARCHITECTURE.md](ARCHITECTURE.md): scheduling sotto-minuto e consegna recuperabile, campioni regionali senza retry, retention logica di 30 giorni con drop fisico mensile, percentili esatti aggregabili, SLA con copertura, matrice piani e ripresa esplicita. Scelte quantitative e adeguamento retention approvati dall'utente; implementazioni M1–M10. M0 resta aperta per i criteri finali. |
| 2026-10-22 | M2.03: controllo SSRF al salvataggio solo locale | Scelta utente: il validatore resta senza rete e rifiuta soltanto nomi locali e IP letterali bloccati; risoluzione DNS, indirizzi e redirect sono verificati all'accesso, unico punto efficace contro il DNS rebinding. |
| 2026-10-22 | M2.03: eccezioni `OUTBOUND_ALLOWED_CIDRS` vuote di default | Scelta utente: allowlist CIDR esplicita per sviluppo/demo, configurazione malformata rifiutata; in produzione deve restare vuota. |
| 2026-10-22 | M2.03: redazione di tutti i valori della query string | Scelta utente: nomi dei parametri conservati, valori sempre `[REDACTED]`, senza euristiche sui nomi. |
| 2026-10-22 | M2.03: IPv6 consentito solo in 2000::/3, NAT64 bloccato | Fail-closed: fuori dal global unicast nessuna destinazione; NAT64 64:ff9b::/96 rifiutato anche con IPv4 pubblico incorporato. Mapped valutati come IPv4, 6to4 con verifica dell'IPv4 incorporato. |
| 2026-10-22 | M2.03: nessun `resolution_timeout_ms` | Deviazione dal piano: `dns_get_record()` non espone timeout; il limite dipende dal resolver di sistema ed è documentato per il budget dei check M3. |
| 2026-10-22 | M2.04: owner/admin/member gestiscono i monitor, viewer sola lettura | Scelta utente; MonitorPolicy rilegge il ruolo fresco ed è rieseguita dalle action sotto lock. |
| 2026-10-22 | M2.04: eliminazione definitiva dei monitor | Scelta utente: hard delete che libera subito la quota; risultati/incidenti futuri useranno FK con cascade. |
| 2026-10-22 | M2.04: piano effettivo provvisorio fino a M9 | Scelta utente: piano salvato pro/business vale come diritto pagato segnaposto; free riceve Pro durante il trial (fine esclusiva), altrimenti Free; il trial non abbassa un piano superiore. Da sostituire con lo stato Stripe in M9. |
| 2026-10-22 | M2.04: mutazioni con redirect e flash, nessuna pagina | Scelta utente: pagine lista/dettaglio/form in M2.05. |
| 2026-10-22 | M2.04: una sola action coesa ManageMonitors | Deviazione dal piano (cinque classi): come ManageTeamMembers, condivide lock Team→Monitor e riautorizzazione senza duplicazione. |
| 2026-10-22 | M2.04: intervallo invariato dopo downgrade non bloccato sulle modifiche | Il minimo di piano si verifica su creazione, cambio intervallo e ripresa; l'adeguamento degli intervalli esistenti resta alla riconciliazione M9 (ADR-010). |
| 2026-10-23 | M2.05: ricerca Scout su `name`, `tls_host`, `dns_name` | Scelta utente: solo colonne in chiaro; URL/header/body cifrati non ricercabili né decifrati, nessuna migration. HTTP/keyword trovabili per nome; un'eventuale colonna host derivata richiede una nuova decisione. |
| 2026-10-23 | M2.05: create → dettaglio, delete → lista | Scelta utente; modifica mirata dei redirect M2.04 (update/pausa/ripresa restano `back()`), test CRUD e matrice aggiornati. |
| 2026-10-23 | M2.05: filtri tipo, stato osservato, stato operativo | Scelta utente; nessun filtro regione. La sospensione di piano prevale sulla pausa nello stato operativo. |
| 2026-10-23 | M2.06: concorrenza via UI su server HTTP reale | Scelta utente: `artisan serve` multi-worker (CMD del Dockerfile) sul database di test isolato con POST Inertia paralleli via Guzzle; nessun Playwright né nuova dipendenza. Prova deterministica tramite attesa del lock osservata in `pg_stat_activity` più raffica parallela come rete di sicurezza. |
| 2026-10-23 | M2.06: contratto payload form↔server | Scelta utente: fixture JSON condivisa (`tests/Fixtures/monitor-form-payloads.json`) verificata da Vitest su `buildPayload()` e inviata da Pest alle rotte di produzione; i default devono coincidere con `MonitorPresenter::defaults()`. |
| 2026-10-23 | MA: pannello di amministrazione fuori specifica | Scelta utente: nuova milestone MA tra M2 e M3 per prove senza vincoli di piano e piani concessi senza sottoscrizione; gestione utenti e impersonificazione pianificate (MA.05/MA.06), non implementate. |
| 2026-10-23 | MA: super admin come flag su database concesso solo da CLI | Scelta utente: `users.is_platform_admin` legato all'ID e non all'email mutabile, revocabile senza redeploy, tracciato in audit; nessun endpoint HTTP promuove un utente. Scartata l'email in variabile d'ambiente. |
| 2026-10-23 | MA: password recente sulle modifiche, 2FA obbligatoria solo in produzione | Scelta utente: requisito ricavato da `app()->isProduction()` senza variabile che lo disattivi; vale anche per Horizon. |
| 2026-10-23 | MA: override test illimitato e piano omaggio | Scelta utente: profilo `internal` con tetti alti ma finiti (nessun valore illimitato, ADR-010) e omaggio Pro/Business con scadenza opzionale; un omaggio non abbassa mai un piano pagato. |
| 2026-10-23 | MA: Horizon unificato al ruolo di piattaforma | Scelta utente: `viewHorizon` delega al gate di piattaforma; `HORIZON_ADMIN_EMAILS` rimossa. |
| 2026-10-23 | pgAdmin opzionale solo su localhost | Richiesta utente: servizio `pgadmin` nel profilo `dbadmin`, porta pubblicata solo su `127.0.0.1`, login da `.env` senza valori predefiniti, solo database di sviluppo, immagine fissata per digest. Strumento di ispezione: le scritture da pgAdmin non passano da scope, policy e audit. |
| 2026-10-23 | Aggiornamento di sicurezza a PHP 8.4.27 | Richiesta utente urgente: correzioni di sicurezza del core (GHSA-w8hw-w9fx-j79w, GHSA-6wmf-4rv5-p3rp) verificate nel changelog di php.net; immagine base fissata con tag patch esplicito e nuovo digest. Vincoli Composer, lockfile ed estensioni invariati. |
| 2026-10-23 | M2.05: filtri invalidi ignorati e segnalati, non errore di validazione | Deviazione dal piano: una validazione GET fallita reindirizza alla URL precedente, che per una lista aperta direttamente è la stessa URL invalida (loop). La pagina mostra `rejectedFilters` come stato di errore. |
| 2026-10-28 | M4.01: stati incidente stile status page | Scelta utente: investigating/identified/monitoring/resolved; stati aperti liberi fra loro, resolved solo verso investigating come riapertura contata. Presa in carico separata (`acknowledged_by`/`acknowledged_at`). |
| 2026-10-28 | M4.01: severità derivata dal `CheckError` | Scelta utente: mappatura esaustiva critical/major/minor in [INCIDENTS.md](INCIDENTS.md), massimo fra regioni; modifica manuale in M4.03/M4.05. |
| 2026-10-28 | M4.01: riapertura entro finestra | Scelta utente: conferma entro `reopen_window_seconds` (900 s) dalla risoluzione riapre lo stesso incidente, oltre ne apre uno nuovo; logica in M4.03. `duration_seconds` = resolved_at − started_at ricalcolata a ogni risoluzione. |
| 2026-10-28 | M4.01: timeline append-only | Correzioni come nuove voci; unica modifica ammessa la pubblicazione una tantum, più l’azzeramento dell’autore richiesto dalla FK alla cancellazione dell’utente. Liste di valori nei CHECK congelate nella migration e confrontate con gli enum da test. |
| 2026-10-28 | M4.02–M4.03 uniti in un solo branch | Scelta utente: conferma regionale e ciclo automatico degli incidenti insieme, così il percorso guasto → incidente → ripristino è verificabile end-to-end. Rotte/UI restano in M4.05. |
| 2026-10-28 | `monitors.consecutive_failures` = progresso verso la conferma | Scelta utente: minimo dei contatori delle regioni configurate (0 se una regione non ha stato corrente); dettaglio per regione in `monitor_region_sequences`. |
| 2026-10-28 | Slot saltato e cambio di revisione interrompono la sequenza | Attuazione di ADR-007: la sequenza prosegue solo se lo slot precedente processato è lo slot programmato immediatamente precedente; ogni modifica non di solo nome (anche soglia e regioni) e ogni pausa incrementano già la revisione. |
| 2026-10-28 | Risoluzione manuale ammessa, riapertura solo automatica | Una risoluzione manuale con guasto persistente viene riaperta dalla conferma successiva entro la finestra; le persone non possono riportare un incidente risolto a uno stato aperto. |
| 2026-10-28 | Contratto `ResultObserver` fra motore e incidenti | Il motore invoca il contratto nella transazione del risultato; l’implementazione vive nell’ambito Incidenti (ADR-001), effetti e risultato si confermano o annullano insieme. |

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

### 2026-10-19 — M0.09: semantiche operative (completato)

- Dipendenze M0.01–M0.08 soddisfatte. Piano approvato dall'utente: consegna documentale, senza anticipare implementazioni applicative; nessun comando Git/gh.
- Scelte confermate: Free/Pro/Business 5/50/200 monitor, intervalli minimi 300/60/30 secondi, 1/3/10 status page; trial Pro di 14 giorni senza carta; grezzi per 30 giorni logici, rollup per 90/365/730 giorni; dati mancanti separati e ripresa esplicita dopo upgrade.
- File: `docs/ARCHITECTURE.md` (ADR-006–ADR-010 e rinvii aggiornati), `docs/TASKS.md`, `docs/BOOTSTRAP.md` e README. Nessuna modifica a codice, manifest, lockfile, workflow o scheduler.
- Decisioni tecniche: prenotazione transazionale con registro persistente di pubblicazione, acquisizione atomica e recupero terminale senza replay di check iniziati; nessun retry di dominio; soglia iniziale tre e conferma dello stesso slot per due regioni; percentili nearest-rank da frequenze di millisecondi; SLA temporale con copertura e sconosciuti espliciti.
- Adeguamenti approvati alla specifica: 30 giorni di accessibilità logica dei grezzi, permanenza fisica fino a circa 61 giorni più ritardo di pulizia con drop mensile verificato; dispatch ogni secondo per intervalli Business di 30 secondi; chiarito il riferimento a retry HTTP escludendo retry che nascondano campioni falliti. Specifica originale conservata, senza riduzioni di scope.
- Scenari automatici assegnati alle milestone proprietarie: crash e duplicati, concorrenza PostgreSQL, risultati fuori ordine, soglie regionali, confini mese/anno/DST, percentili aggregati, dati mancanti, downgrade e isolamento tenant. Le verifiche documentali non certificano queste implementazioni future.
- Verifiche documentali: controllo Python locale superato su quattro Markdown e 39 destinazioni di link locali esistenti; dieci ADR ordinati con sezioni richieste, metadati dei cinque nuovi record, matrice dei piani e invarianti principali coerenti, M0.09 completato/M1.01 ancora aperto, newline finali e assenza di spazi finali. Revisione manuale di coerenza con piano approvato, specifica e configurazioni esistenti; corretta la continuità della tabella del registro decisioni. Formatter PHP, Larastan, TypeScript, lint/build e suite applicative non eseguiti: sole modifiche Markdown e nessun comportamento eseguibile aggiunto.
- M0.09 completato come consegna documentale; M0 complessiva resta aperta. Prossimo passo: attestare smoke test e riproducibilità da checkout pulito, con operazioni Git esclusivamente dell'utente; poi M1.01 (utenti, team, membership, ruoli, timezone e team corrente). Nessuna nuova verifica CI dichiarata.

### 2026-10-19 — M0: verifica finale da checkout pulito (completata)

- Checkout creato dall'utente in `/private/tmp/signalbox-m0-clean`; nessun comando Git/gh eseguito dall'agente. Verificata l'assenza iniziale di `.env`, vendor, node_modules, build frontend e config cache.
- Prova con progetto Compose `signalbox-m0-clean`, nuovi volumi, porta web 18090 e credenziali locali generate senza stampa. Nessuna modifica ai servizi o volumi di sviluppo.
- Bootstrap superato: `docker compose config --quiet`, `docker compose build web node` (layer in cache), `docker compose run --rm --no-deps web composer install --prefer-dist --no-interaction --no-progress` (157 installazioni, nessun aggiornamento), `docker compose run --rm node npm ci --ignore-scripts --strict-peer-deps` (292 pacchetti), generazione chiave, config clear, build iniziale, avvio PostgreSQL/Redis, quattro migration su database nuovo e avvio web/Horizon/scheduler. Nessuna pretesa di build immagini senza cache; installazioni applicative realmente da zero.
- `./scripts/verify.sh` superato con exit 0: PHP 8.4.26, PHP-CS-Fixer dry-run 49 file senza modifiche, Larastan livello 8 zero errori, Pest 46 test/125 asserzioni senza skip; ESLint, Prettier, TypeScript, 5 test strumenti, 3 test DOM su 2 file e build Vite 8.3.4. Runtime frontend effettivo Node 22.23.3/npm 10.9.9: compatibile con i vincoli del progetto, distinto dai patch CI già documentati. Audit npm zero vulnerabilità note al momento della prova.
- `composer validate --strict --no-check-publish` e `composer check-platform-reqs` superati nel container web. Confronto SHA-256 senza Git: 70 file di sorgente/configurazione/manifest/lock corrispondono al progetto di lavoro. Il clone contiene la documentazione committata fino a M0.08; gli aggiornamenti Markdown M0.09 presenti nel progetto principale non sono stati copiati nel checkout né necessari per eseguire il codice invariato. Nessun identificatore di commit dichiarato senza output utente.
- Smoke reale: home e `/up` HTTP 200 dalla porta host 18090; tutti e tre gli asset CSS/JS del manifest serviti, protocollo JSON Inertia con versione corrente e componente Home corretti; `/horizon` anonimo HTTP 403, `horizon:status` running, `schedule:list` con snapshot ogni cinque minuti e log del processo scheduler attivo. Reverb avviato con credenziali locali generate e handshake WebSocket HTTP 101 riuscito. Web/PostgreSQL/Redis (inclusi servizi test) healthy; queue/scheduler/Reverb running.
- Primo lancio dello script e prima richiesta HTTP host bloccati dal sandbox, poi rieseguiti con autorizzazione estesa e superati; non erano errori applicativi. Avvio Compose parallelo iniziale ha segnalato rete già creata dall'altro runner, senza impedire installazioni o controlli. Nessuna correzione applicativa necessaria.
- Evidenza completa di verifica conservata localmente in `/private/tmp/signalbox-m0-final-verify.log`. Ambiente di prova lasciato attivo su `http://127.0.0.1:18090` per revisione; da quella cartella `docker compose --profile realtime --profile testing down` arresta/rimuove i soli container del progetto isolato preservando i volumi. Nessuna cancellazione di dati o volumi eseguita.
- Documenti aggiornati nel progetto principale: TASKS, ARCHITECTURE, BOOTSTRAP, DOCKER e README. Controllo Python finale superato su cinque Markdown, 46 destinazioni di link locali, newline/spazi finali e coerenza dello stato M0 completata/M1.01 aperto.
- Limiti: nessuna sessione browser end-to-end, benchmark, test delle funzionalità di dominio o nuova esecuzione remota CI; PHP 8.5 non rieseguito in questa prova. La CI verde per PHP 8.4/8.5 resta l'evidenza confermata dall'utente in M0.07. M0 completata sulla base di questa evidenza e della nuova riproducibilità/smoke locale. Prossimo task pronto M1.01: utenti, team, membership, ruoli, timezone e team corrente.

### 2026-10-19 — Consegna per avvio M1 il 2026-10-20

- Richiesta utente: aggiornare i documenti e riprendere domani con M1. Aggiornati TASKS e README con data di ripartenza, primo task pronto, riferimenti da rileggere e distinzione tra progetto principale e checkout temporaneo.
- Nessun task M1 avviato, nessuna modifica al codice e nessun comando Git/gh eseguito. Verifica limitata ai documenti modificati; le suite applicative restano quelle già superate nella prova finale M0.

### 2026-10-20 — M1.01: identità e team (completato)

- Dipendenze M0 soddisfatte; implementazione autorizzata del piano M1.01. Nessun comando Git/gh.
- Perimetro: migration incrementali, Team/Membership, enum ruoli/piani, CreateTeam transazionale, team corrente verificato tramite membership, factory e seeder sintetico local/testing, test PostgreSQL.
- Decisione utente: più owner consentiti; owner_id identifica il titolare principale, sempre owner nella membership. Timezone default UTC; trial iniziale di 14 giorni esatti. UI/auth/2FA, inviti, gestione membri, TeamScope e policy restano nei task successivi.
- File applicativi: `app/Actions/Identity/CreateTeam.php`, enum in `app/Enums/Identity` e `app/Enums/Billing`, modelli User/Team/Membership; tre nuove migration incrementali, factory Team/Membership e seeder DatabaseSeeder/IdentitySeeder. Nuovi test in `tests/Integration/TeamIdentityTest.php`. Documenti: nuovo IDENTITY, aggiornati README, BOOTSTRAP, ARCHITECTURE, TESTING e questo piano. Nessuna dipendenza, manifest, lockfile o rotta modificata.
- Implementazione: slug univoco, vincoli PostgreSQL su ruolo/piano/coppia membership/FK/ordine trial; owner principale non eliminabile mentre possiede team; eliminazione team con cascade membership e azzeramento preferenze. CreateTeam valida input, blocca la riga utente e scrive aggregato/preferenza nella stessa transazione, preservando selezioni esistenti. Resolver rilegge preferenza e membership senza fallback né fiducia nelle relazioni già caricate. Protezione owner e trial contro mass assignment; nessun diritto effettivo o policy anticipato.
- Factory User resta indipendente; factory Team crea il titolare come owner, Membership supporta tutti i ruoli. Seeder local/testing sintetico e ripetibile: quattro utenti condivisi fra due team, nessuna rete, password fixture pubblica e hashata; rifiutato anche con --force in produzione. Trial storico e credenziali non vengono rinnovati al rerun.
- Test nuovi: 30 casi/130 asserzioni sul PostgreSQL isolato, inclusi invalidi, duplicati, rollback dopo inserimento team, membership revocata/ID estraneo/selezione obsoleta, ruoli e più owner, cancellazioni, durata UTC del trial attraverso cambio DST, factory, seeder ripetuto/guard produzione e rollback/reapply migration senza alterare utenti esistenti. Due connessioni reali verificano la contesa sulla riga owner: la seconda creazione incontra SQLSTATE 55P03 con lock timeout 100 ms mentre la prima è non confermata; fixture committata di prova rimossa in finally. Non è una dichiarazione di suite parallel-safe.
- Controlli superati: `docker compose run --rm php-test composer format -- --sequential` con PHP 8.4.26; `./scripts/verify.sh` exit 0, formatter dry-run su 61 file, Larastan livello 8 zero errori, 76 test Pest/255 asserzioni senza skip; ESLint, Prettier, TypeScript, 5 test qualità, 3 test DOM e build Vite 8.3.4. Anche `docker compose run --rm php-test composer test -- --fail-on-skipped --compact` exit 0 con gli stessi 76 test/255 asserzioni. Log locali `/private/tmp/signalbox-m1-verify.log` e `/private/tmp/signalbox-m1-no-skips.log`.
- Host PHP 8.5.9: analisi Larastan iniziale superata; `composer test -- --compact` exit 0 con 37 test/88 asserzioni e 39 integrazioni saltate come atteso senza opt-in. Non equivale a verifica integrata PHP 8.5. Formatter host iniziale senza modifiche, poi rieseguito nel runtime PHP 8.4 di riferimento. Nessuna nuova CI remota o sessione browser end-to-end.
- Correzioni durante verifica: API Pest di skip a livello file non supportata, sostituita con beforeEach senza accesso DB in assenza di opt-in; test guard seeder inizialmente fermato dalla conferma standard di Artisan, corretto usando --force per raggiungere e verificare il rifiuto applicativo. Tutti i controlli finali sopra sono successivi alle correzioni.
- Docker accessibile con esecuzione autorizzata fuori sandbox; avviati soltanto servizi test isolati, lasciati attivi. Nessuna migration/seeding sul database di sviluppo, nessuna modifica .env, reset applicativo o operazione Git/gh. L'ambiente temporaneo M0 non è stato usato né gestito.
- Verifica documentale finale superata su sei Markdown e 54 destinazioni di link locali, newline/spazi finali e coerenza checklist M1.01 completato/M1.02 aperto; revisione della documentazione inglese del codice.
- Completato soltanto M1.01. M1 complessiva resta aperta; prossimo passo M1.02. Cambi team/inviti/ultimo owner M1.03, TeamScope/contesto/policy M1.04 e matrice ingressi M1.05 restano espliciti.

### 2026-10-20 — M1.02: autenticazione web e 2FA (completato)

- Piano autorizzato: Fortify ^1.41, registrazione atomica con primo team e slug generato, verifica email obbligatoria, 2FA facoltativa, UI/email inglesi. Task successivi M1.03–M1.05 invariati; nessuna operazione Git/gh.
- Dipendenza M1.01 soddisfatta. Backend: RegisterUser transazionale compone CreateTeam e notifica dopo commit; richieste validate, risposte e provider Fortify applicativi, rotte esplicite e account minimale. Login rigenera sessione e registra subito hash password per revoca; logout invalida sessione/CSRF. Verifica email obbligatoria, reset uniforme con token a tempo/monouso e lock sulla riga utente; 2FA con secret/recovery cifrati, conferma iniziale, challenge server-side a 300 secondi e fingerprint credenziali, password recente 900 secondi e lock condiviso per le mutazioni. Replay TOTP delegato a Google2FA con clock applicativo, cache per segreto e contatori normalizzati. No-store, props minime ed esclusione codici dal flash.
- File backend: nuove action Identity RegisterUser/ResetUserPassword/TrackTwoFactorChallenge, controller Auth e AccountController, Form Request Auth, middleware ProtectTwoFactorState/NoStoreAuthentication e risposte Auth; provider FortifyServiceProvider, config/fortify.php e routes/auth.php. Aggiornati User, bootstrap provider/middleware/eccezioni, config/auth.php, web routes e middleware Inertia. Migration incrementale 000004 per tre campi nullable 2FA e indice univoco lower(email), con blocco esplicito su collisioni pregresse e rollback verificato.
- Frontend: layout/form auth condivisi, sette pagine Auth e due Account, home con navigazione pertinente. Errori/caricamento/focus/autocomplete, scelta TOTP/recovery, setup QR/chiave manuale, conferma, annullamento/disattivazione, recovery fetch/hide/regenerate e scadenza password gestiti senza conservare segreti nella cronologia Inertia. Riepilogo team tramite resolver di membership esistente; nessun CRUD tenant anticipato.
- Dipendenze: Fortify ^1.41 risolto v1.41.0, 14 nuove installazioni senza aggiornamenti preesistenti nel require iniziale; passkeys transitive non abilitate. Rilevato advisory effettivo sul precedente HttpFoundation v8.0.15 (CVE-2026-108082). Adeguamento esplicito circoscritto: update mirato con minimal-changes ha cambiato soltanto HttpFoundation a v7.4.21, compatibile con il vincolo Laravel e PHP 8.4, poi Composer ha riportato zero advisory. Motivo e versioni in DEPENDENCIES; nessun lock npm o stack prodotto sostituito.
- Test aggiunti: AuthenticationSurfaceTest, AuthenticationTest, TwoFactorAuthenticationTest e helper AuthFixture; 51 nuovi casi backend rispetto ai 76 precedenti. Percorsi positivi/negativi, validazioni, vincoli, rollback senza notifiche, notifiche fake dopo commit, sessioni/CSRF/rate limit, firme/hash/scadenze, reset/recovery monouso, challenge pendenti e credenziali modificate, dati minimali/isolamento e migration reversibili. Tre probe reali a due connessioni PostgreSQL verificano contesa su indice email e riga User per reset e recovery, con lock timeout 100 ms e pulizia fixture committate. Non è una suite parallel-safe o una prova di carico.
- Test frontend: nuovo Authentication.test.tsx e fixture Inertia estesa; sette nuovi casi, dieci DOM totali su tre file. Interazioni reali con provider/form, transport intercettato, submit/loading/errori e cancellazione password, registrazione, cambio metodo 2FA da tastiera, resend, empty state, recovery on-demand/hide/remount e gestione HTTP 423. Adeguati test home e props pubbliche.
- Controlli superati: formatter nel container PHP 8.4.26; `./scripts/verify.sh` exit 0 con PHP-CS-Fixer dry-run su 85 file, Larastan livello 8 zero errori, 127 test Pest/629 asserzioni senza skip, ESLint/Prettier/TypeScript, 5 test qualità, 10 test DOM e build Vite 8.3.4. `docker compose run --rm php-test composer test -- --fail-on-skipped --compact` exit 0 con gli stessi 127 test/629 asserzioni. `composer validate --strict --no-check-publish` e `composer check-platform-reqs` host PHP 8.5.9 superati; piattaforma superata anche nel container PHP 8.4.26.
- Verifica host senza servizi: `composer test -- --compact` exit 0, 50 test/150 asserzioni e 77 integrazioni saltate come previsto senza opt-in. Non equivale a esecuzione integrata PHP 8.5; nessun nuovo esito remoto CI dichiarato. Log finali locali: `/private/tmp/signalbox-m1.02-verify.log`, `/private/tmp/signalbox-m1.02-no-skips.log`, `/private/tmp/signalbox-m1.02-platform.log` e `/private/tmp/signalbox-m1.02-offline.log`.
- Correzioni rilevate dai test: conferma password rimandava a una precedente URL JSON, ora ritorna deliberatamente alla pagina sicurezza; replay TOTP deve normalizzare anche contatori numerici restituiti come stringhe dalla cache; errori recovery usano il relativo campo e la conferma setup il bag Fortify corretto. Corrette inoltre tipizzazione/docblock e callback frontend per i controlli rigorosi, senza disabilitare regole o baseline.
- Documenti: nuovo AUTHENTICATION; aggiornati DEPENDENCIES, BOOTSTRAP, IDENTITY, ARCHITECTURE, TESTING, README e questo registro. Decisioni utente conservate; documentati mailer log locale, migrazione con collisioni, ricordo sessione standard, limiti della cache replay e assenza di tenant context/policy in questo task.
- Limiti: nessun provider email reale, scansione QR con dispositivo reale, browser end-to-end, deploy o integrazione completa PHP 8.5/CI remota. Nessuna migration/seeding su database di sviluppo, nessuna modifica .env/chiavi, reset di volumi, comando Git/gh o gestione dell'ambiente temporaneo M0. Servizi test isolati lasciati attivi. Primo require Composer bloccato dalla rete sandbox e ripristinato automaticamente, poi riuscito con esecuzione autorizzata.
- Verifica documentale finale superata: otto Markdown, 77 destinazioni locali valide, newline/spazi finali e checklist M1.02 completo/M1.03 aperto. Commenti di configurazione/auth/home riallineati al comportamento attuale; nessuna modifica eseguibile dopo i controlli finali.
- M1.02 completato; M1 complessiva resta aperta. Prossimo task M1.03: inviti, gestione membri/cambio team e vincoli dell'ultimo owner; M1.04 introduce contesto/policy e M1.05 estende la matrice di isolamento.

### 2026-10-20 — Correzione connessione PostgreSQL dal browser (completata)

- Segnalazione utente: login/registrazione HTTP su porta 8000 falliscono verso 127.0.0.1:5432, mentre migrate:status nel container funziona. Verificato ServeCommand: senza --no-reload filtra gli override di Compose nel processo PHP figlio, che rilegge DB_HOST dal .env host.
- Correzione applicata a `docker/php/Dockerfile`: `artisan serve --no-reload` preserva gli override Compose nel figlio HTTP. `.env` resta orientato ai comandi host, senza modificare credenziali o pubblicare PostgreSQL. Dopo cambi .env serve `docker compose restart web`; documentato in DOCKER.
- Nuovo `tests/Integration/ContainerWebEnvironmentTest.php`: legge il comando reale dell'immagine, avvia server loopback su porta effimera con sole coordinate del database test e verifica login CSRF con identità inesistente. Prima della correzione HTTP 500 (test rosso), dopo HTTP 422 con errors.email (1 test/3 asserzioni). Il primo tentativo verde usava sessioni array non persistenti fra richieste e restituiva 419: corretta la fixture con sessioni cookie in memoria; nessuna esclusione CSRF. Il processo viene arrestato in finally.
- Ricostruita immagine locale con layer applicativi in cache; ricreato soltanto `web` tramite `docker compose up -d --no-deps web`. Nessuna migration, seeding, cancellazione di volumi o operazione Git/gh; PostgreSQL, code e scheduler non ricreati. Le migration M1 osservate già applicate dall'utente prima della correzione non sono state rieseguite.
- Smoke sulla porta pubblicata 8000: GET /login HTTP 200, POST /login con credenziali sintetiche inesistenti HTTP 422, POST /register con password volutamente troppo corta HTTP 422. Query reali verso il database di sviluppo riuscite senza creare account/team; cookie/token tenuti in memoria e non stampati.
- Verifiche superate: formatter PHP nel container e `docker compose run --rm php-test composer verify` exit 0, PHP-CS-Fixer su 86 file, Larastan livello 8 zero errori, 128 test Pest/632 asserzioni senza skip. Il nuovo test mirato è passato anche con --fail-on-skipped. Log in `/private/tmp/signalbox-web-env-red.log`, `signalbox-web-env-green.log`, `signalbox-web-env-build.log` e `signalbox-web-env-verify.log`. Frontend non rieseguito: nessuna modifica JS/CSS o agli asset; restano gli esiti M1.02. Nessuna nuova CI/PHP 8.5 integrata o verifica completa browser dichiarata.
- Documenti aggiornati: DOCKER, TESTING e TASKS. Correzione completata; prossimo task funzionale resta M1.03.

### 2026-10-21 — Regressione CI backend (correzione verificata localmente)

- Richiesta utente: correggere il fallimento PHP 8.4 prima di M1.03. Dipendenze M0.07/M1.02 disponibili; nessuna operazione Git/gh.
- Riproduzione in copia temporanea senza .env, public/hot e public/build: test originale rosso con GET /login HTTP 500. Diagnostica estesa nella sola copia ha confermato ViteManifestNotFoundException per public/build/manifest.json. I job frontend/backend non condividono gli asset. Riprodotti separatamente 13 warning della superficie auth: phpdotenv tentava di leggere il .env assente, riportato da PHPUnit anche se la lettura usa @.
- Implementazione: ContainerWebEnvironmentTest richiede Auth/Login con protocollo Inertia e versione asset coerente sia con build sia senza build. Rimangono server reale, database test, cookie session e POST CSRF con HTTP 422/errors.email. Verificati avvio del processo, header Inertia e componente; stderr del solo server sintetico incluso negli errori, senza stampare response body o cookie. Processo sempre arrestato in finally. Workflow condiviso PHP 8.4/8.5 prepara .env.testing vuoto prima di Composer; nessun valore o segreto aggiunto al file.
- Verifiche superate: `docker compose run --rm --volume /private/tmp/signalbox-ci-regression-hrkmuddq:/var/www/html php-test composer test -- --fail-on-skipped --fail-on-warning --display-warnings` exit 0, **128 test/636 asserzioni, zero skip e zero warning**, PHP 8.4.26, senza asset o .env di sviluppo; fixture .env.testing vuota verificata. Test mirato nel progetto principale con build presente: 1 test/7 asserzioni, stessi gate zero skip/warning. Larastan livello 8 zero errori; PHP-CS-Fixer applicato e dry-run su 86 file, zero file da correggere. YAML parsato e verificati ancore/step identici nei due job e preparazione ambiente prima dell'installazione.
- File interessati: .github/workflows/ci.yml, tests/Integration/ContainerWebEnvironmentTest.php, docs/CI.md, docs/TESTING.md e questo registro. Link locali e formattazione Markdown verificati. Log locali: /private/tmp/signalbox-ci-red.log, signalbox-ci-cause.log, signalbox-ci-warnings-before.log, signalbox-ci-green.log, signalbox-ci-analyse.log, signalbox-ci-format-check.log e signalbox-ci-local-build.log.
- Limiti: actionlint non disponibile localmente, eseguita validazione YAML/ancore e dello step aggiunto; nessuna nuova CI remota o integrazione PHP 8.5 dichiarata. Frontend non rieseguito perché nessuna modifica JS/CSS o build pipeline frontend. Nessuna modifica .env/chiavi o migration sul database di sviluppo, nessun riavvio/reset dei processi applicativi. Avviati soltanto servizi test isolati e lasciati attivi; nessuna operazione Git/gh. Prossimo passo operativo: push dell'utente e riscontro CI prima di riprendere M1.03.
- M1.03 rimandato senza modifiche implementative; decisioni concordate conservate: inviti 7 giorni/email verificata, owner/admin con admin limitati a member/viewer, trasferimento esplicito dal titolare a owner esistente con password recente, registrazione da invito senza team personale.

### 2026-10-21 — M1.03: inviti e gestione team (completato)

- Dipendenze M1.01/M1.02 soddisfatte; autorizzazione esplicita a implementare il piano concordato. Inviti di 7 giorni, accettazione esplicita con email verificata corrispondente, reinvio con rotazione token e registrazione da invito senza team personale.
- Owner gestiscono tutti i ruoli; admin limitati a member/viewer. Più owner restano consentiti; ultimo owner e titolare principale protetti. Trasferimento esplicito dal titolare ad altro owner con password recente; il precedente titolare resta owner.
- Mutazioni serializzate sul team e selezione corrente coordinata con revoca membership; controlli di autorizzazione condivisi senza anticipare TeamScope/contesto generale di M1.04. UI/email inglesi, test PostgreSQL e DOM, documentazione e controlli completi previsti. Nessuna operazione Git/gh.

- Implementazione conclusa: migration incrementale team_invitations, modello senza mass assignment dei campi sensibili, TeamAccess/InvitationLinks e action ManageTeamMembers/SwitchTeam/IssueTeamInvitation/AcceptTeamInvitation/RegisterInvitedUser. Adapter HTTP di sessione, Form Request e controller dedicati; nuove rotte web teams/invitations e cinque pagine Inertia. Account offre elenco team e continuazione invito; email verification/password confirmation hanno ritorni interni espliciti e rivalidati. Nessun TeamScope o policy generale anticipato.
- Semantica registrata: una riga per team/email rappresenta il ciclo invito, con rotazione/riuso per inviti scaduti/revocati. Duplicati pendenti rifiutati, membri esistenti non reinvitati; accettazione non cambia ruoli preesistenti e replay non ripristina membri rimossi né riseleziona team. Invio sincrono dopo commit per non persistere token grezzi in coda; errore trasporto lascia invito salvato/reinviabile. Nessuna quota membri inventata dai limiti monitor.
- Correzioni emerse dai test: i Pivot caricati con query standalone non hanno metadati delle chiavi di relazione; update/delete usano esplicitamente entrambe le colonne composite. Il middleware di verifica può cambiare url.intended: adapter VerifyEmailResponse riprende il contesto invito verificato senza accettazione automatica. Gli errori di invito non associati a un campo sono mostrati anche nel form condiviso. Il test di errore invio simula send del dispatcher, non il metodo statico route della facade.
- Verifiche superate: `./scripts/verify.sh` exit 0, PHP 8.4.26, PHP-CS-Fixer dry-run su 106 file, Larastan livello 8 zero errori, **178 test Pest/924 asserzioni**, lint/formato/TypeScript, 5 test qualità, **20 test DOM** e build Vite. Ultima revisione delle eccezioni di dominio verificata con formatter dry-run e Larastan nuovamente verdi; suite completa nella copia CI con `.env.testing` vuoto, senza .env di sviluppo né public/hot/public/build: `composer test -- --fail-on-skipped --fail-on-warning --display-warnings --stop-on-failure`, exit 0, stessi 178 test/924 asserzioni, zero skip/warning.
- Copertura nuova: 50 casi backend oltre ai 128 precedenti, 10 casi DOM oltre ai 10 precedenti; gerarchia ruoli, ID estranei, revoche, password recente, proprietà, registrazione dedicata, verifica firmata/2FA e cambio account, scadenza immediatamente prima/al confine, duplicati/reinvio, rollback aggregato e fallimento trasporto. Cinque probe reali PostgreSQL a due connessioni verificano contesa acceptance/acceptance, role/transfer, switch/remove, remove/switch e transfer/remove con timeout database di 100 ms e pulizia fixture committate. Non è un benchmark o una dichiarazione di suite parallel-safe.
- Deviazione esplicita: il checkout corrente non conteneva la correzione CI già autorizzata nella sessione precedente; riapplicati lo stesso probe HTTP Inertia e lo step `.env.testing` ai due job backend. YAML/ancore condivise verificati. Prima copia temporanea di regressione incompleta dei nuovi sorgenti React: 8 assert Inertia falliti; aggiornata con tutti i sorgenti e rieseguita integralmente verde, senza modificare i test per aggirare gli assert.
- File/documenti: nuovi servizi/action/adapter e migration sopra, routes/teams.php, resources/js/pages/Teams, tests/Integration/TeamMembershipTest.php, tests/Support/InvitationFixture.php e tests/frontend/Teams.test.tsx. Aggiornati controller/risposte/provider auth, middleware cache/referrer/status, account, AuthForm, test rollback identità, workflow e test server. Nuovo TEAMS.md; aggiornati README, IDENTITY, AUTHENTICATION, BOOTSTRAP, ARCHITECTURE, TESTING, CI e TASKS. Nessun manifest o lockfile modificato. Verifica link e formattazione Markdown completata.
- Log locali: /private/tmp/signalbox-m1.03-verify.log, signalbox-m1.03-ci-no-assets.log, signalbox-m1.03-analyse.log, signalbox-m1.03-format-check.log, signalbox-m1.03-tests.log e signalbox-m1.03-dom.log. Nessuna nuova CI remota, esecuzione integrata PHP 8.5, browser end-to-end o consegna email reale dichiarata; actionlint non disponibile, verificati YAML e step condivisi. Nessuna migration/seeding sul database di sviluppo, modifica .env/chiavi, operazione Git/gh, reset o riavvio dei processi applicativi. Test isolati lasciati attivi.
- Prossimo passo: applicare esplicitamente la nuova migration nell'ambiente di sviluppo scelto per provare i flussi; Git/push e conferma CI restano all'utente. Task applicativo successivo M1.04; M1 complessiva resta aperta fino a M1.05.

### 2026-10-21 — Revisione leggibilità M1.03 (completata)

- Richiesta utente: correggere PHPDoc/annotazioni dentro i metodi, commenti eccessivi e spaziatura insufficiente. La documentazione con tag viene collocata prima delle dichiarazioni; nei corpi soltanto commenti ordinari utili. Aggiornata la regola AGENTS che aveva richiesto documentazione per ogni variabile locale.
- Revisione del codice M1.03 e dei suoi adapter/test: eliminare docblock locali e inseriti negli argomenti, espandere passaggi compressi e rendere espliciti i tipi senza cast documentali. Comportamento, permessi e transazioni restano quelli approvati. Verifiche PHP/frontend e documentazione previste; nessuna operazione Git/gh.

- Revisione completata su action/servizi, controller e adapter, modello/notifica/migration/rotte, test PHP e componenti/test TSX coinvolti in M1.03. Eliminati PHPDoc/annotazioni locali e blocchi inseriti negli argomenti dei callback; ridotti i commenti ridondanti. Contratti e forme dei dati sono documentati prima di metodi, classi e interfacce. Passaggi logici separati da righe vuote, array e query espansi, controlli e risultati distinti.
- Struttura: TeamController ha metodi privati per le proiezioni; i permessi degli inviti filtrano la query mantenendo la medesima proiezione autorizzata. Controller comune risolve User tramite controllo reale del tipo, senza @var locali né soppressioni Larastan. Controlli di proprietà e continuazione esplicitati con guardie leggibili. React usa interfacce per le props e handler nominati; il flusso invito non usa più ternari annidati per login/email/verifica/accettazione.
- Convenzione aggiornata in AGENTS, ADR-005 e QUALITY; TEAMS riallineato. La precedente richiesta di documentare ogni variabile locale è sostituita dall'indicazione esplicita dell'utente. PHP-CS-Fixer resta l'unico formatter PHP, con array_indentation e method_chaining_indentation espliciti; nessun formatter o pacchetto aggiunto.
- Verifiche: audit token/AST su **28 file PHP e 8 TSX**, nessun docblock nei corpi o tra gli argomenti dei callback. `./scripts/verify.sh` exit 0: PHP 8.4.26, formatter dry-run su 106 file, Larastan livello 8 zero errori, **178 test Pest/924 asserzioni**, lint/formato/TypeScript, 5 test qualità, **20 test DOM** e build. Nessun test applicativo aggiunto per rispecchiare la formattazione; le suite esistenti verificano le transizioni e i flussi dopo il refactoring.
- Log: /private/tmp/signalbox-readability-verify.log, signalbox-readability-format.log, signalbox-readability-analyse.log e signalbox-readability-prettier.log. Nessuna verifica remota CI/PHP 8.5 integrata o browser end-to-end dichiarata; nessuna modifica dati/credenziali, migration applicativa, reset/riavvio dei servizi di sviluppo o operazione Git/gh. M1.03 resta completo e M1.04 non avviato.

### 2026-10-22 — Risoluzione conflitti branch (file riconciliati, chiusura Git riservata all’utente)

- Richiesta utente: risolvere i conflitti presenti; nessuna operazione Git/gh. Conservate implementazione/revisione M1.03 e correzione CI precedente.
- Riconciliati `tests/Integration/ContainerWebEnvironmentTest.php`, `docs/CI.md` e questo registro: test Inertia/CSRF mantenuto senza annotazioni locali obsolete; conservate entrambe le cronologie, eliminata spiegazione CI duplicata e riallineato il riepilogo al completamento M1.03. Nessuna modifica di scope.
- Verifiche: scansione dei marcatori anche nei file nascosti (esclusi dipendenze, storage e .git), nessun residuo; newline/spazi finali Markdown verificati. `docker compose run --rm php-test composer verify` exit 0, PHP 8.4.26, PHP-CS-Fixer su 106 file senza correzioni, Larastan livello 8 senza errori, 178 test Pest/924 asserzioni superati, incluso il probe HTTP coinvolto.
- Frontend non rieseguito: nessuna modifica frontend; nessuna nuova CI remota/PHP 8.5 integrata. Accesso Docker inizialmente negato dalla sandbox, verifica poi riuscita con esecuzione autorizzata. Usati solo servizi test isolati, lasciati attivi.
- Stato Git non verificabile dall’agente per AGENTS: richiesti all’utente `git status` e `git diff --name-only --diff-filter=U` per escludere conflitti senza marcatori; staging e prosecuzione merge/rebase restano all’utente. Task funzionale successivo invariato: M1.04.

### 2026-10-22 — M1.04: contesto tenant, scope e policy (completato)

- Implementazione del piano autorizzata dall’utente. Dipendenze M0/M1.01–M1.03 soddisfatte secondo il registro. Nessuna operazione Git/gh.
- Piano: TenantContext scoped con esecuzione annidata e pulizia finally; TeamScope e scritture coerenti per TeamInvitation e fixture riusabile; resolver capability ristretto; middleware HTTP prima del binding; policy team/inviti condivise con TeamAccess e rivalidazione transazionale; middleware job con identificatori scalari e harness CLI.
- Test previsti: assenza/annidamento/pulizia contesto, isolamento query e scritture PostgreSQL, binding e ruoli, regressioni invito/cambio team, job serializzati A → errore → B; formatter, Larastan e verifica completa. Documentare risultati e limiti alla chiusura; M1.05 resta separato.

- Implementazione completata: TenantContext scoped, MissingTenantContext, TeamScope e BelongsToTeam; il contesto è distinto dall’autorità e ripristinato in finally anche in operazioni annidate. Middleware HTTP sulle rotte team prima di SubstituteBindings, con membership fresca e destinazione esplicita indipendente dalla preferenza corrente. User/Team/bootstrap membership restano fuori dallo scope.
- TeamInvitation adotta il contratto tenant. InvitationLinks è l’unico resolver capability con rimozione ristretta dello scope; verifica digest, scadenza e revoca prima di restituire il record. AcceptTeamInvitation deriva il contesto dalla capability e rivalida sotto lock; IssueTeamInvitation/revoke stabiliscono il proprio contesto e mantengono le autorizzazioni sotto lock. Nessun cambiamento ai flussi di invito o alla gerarchia owner/admin/member/viewer.
- TeamPolicy/TeamInvitationPolicy applicate nei controller, con regole condivise TeamAccess e controlli di dominio transazionali conservati. TenantJob e UseTenantContext definiscono il confine per job con soli ID scalari; job/CLI applicativi non anticipati. Test con coda Redis reale, serializzazione e un singolo worker senza reset del container fra i tre lavori A con errore, A con ID estraneo e B riuscito; comando Artisan soltanto nel test.
- Protezioni aggiuntive necessarie dopo ispezione Eloquent: fresh/refresh/refreshForUpdate e newQueryForRestoration mantengono lo scope; eventi updating proteggono increment/decrement che non eseguono saving. Raw SQL, bulk insert/upsert, aggiornamenti massivi del team_id, scritture quiet e rimozioni manuali degli scope restano operazioni fidate, da gestire con predicati e invarianti espliciti. Limiti documentati in TENANCY, senza dichiarare isolamento database RLS.
- Test aggiunti: quattro casi unitari e tredici integrazioni, 17 casi/115 asserzioni oltre la baseline. Copertura di contesto assente/annidato/invalido, pulizia dopo eccezioni, query aggregate/relazioni/join, scritture e cancellazioni, ownership contraffatta/riassegnata, reload e restoration, binding tenant prima del controller, preferenza differente, revoca membership, quattro ruoli, ingresso capability anonimo e worker/CLI. Conservati i test concorrenti PostgreSQL M1.03; le assert private fuori richiesta entrano ora nel contesto esplicito.
- Controlli superati: formatter PHP 8.4.26 e Larastan livello 8 zero errori; test mirati con --fail-on-skipped, 67 test/403 asserzioni. `./scripts/verify.sh` exit 0: PHP-CS-Fixer dry-run su 119 file, Larastan, **195 test Pest/1039 asserzioni**, ESLint/Prettier/TypeScript, 5 test qualità, **20 test DOM** e build Vite. Verificati sette Markdown e 68 destinazioni locali, newline e assenza di spazi finali.
- Correzioni durante verifica: generici del contratto Scope allineati alle firme locali Laravel; la fixture PostgreSQL temporanea non può avere FK verso teams permanente, quindi la relazione fixture usa team sintetici reali senza FK temporanea (FK di produzione già coperte dalle regressioni). Il worker reale va risolto dalla binding queue.worker, non costruito tramite la classe priva dei callback container. Errori corretti prima delle verifiche verdi, senza disabilitare controlli.
- File/documenti: nuovi servizi/contratto/middleware/scope/trait/eccezione e due policy, due fixture e due suite test. Aggiornati provider, bootstrap, rotte team, action/controller inviti/team, descrizioni TeamAccess/TeamRole e assert M1.03. Nuovo TENANCY; aggiornati README, TEAMS, IDENTITY, ARCHITECTURE, TESTING e TASKS. Nessuna modifica a dipendenze, lockfile o schema applicativo. Rimossi soltanto PHPDoc inline preesistenti dai callback bootstrap per rispettare AGENTS; formatter e dry-run finali nuovamente verdi su 119 file, nessuna modifica eseguibile dopo la verifica completa.
- Log locali: /private/tmp/signalbox-m1.04-targeted.log, signalbox-m1.04-tests.log, signalbox-m1.04-format.log, signalbox-m1.04-final-format.log e signalbox-m1.04-verify.log. Nessuna nuova CI remota, esecuzione integrata PHP 8.5, browser end-to-end o benchmark dichiarata. Accesso Docker inizialmente negato dalla sandbox, poi verifiche autorizzate sui soli servizi test isolati, lasciati attivi. Nessuna migration/seeding su database di sviluppo, modifica .env/chiavi, reset/riavvio applicativo o operazione Git/gh.
- M1.04 completato; M1 complessiva resta aperta. Prossimo passo M1.05: matrice parametrica completa degli ingressi disponibili; policy/integrazioni per API, monitor, ricerca, cache, canali e accessi pubblici saranno estese con le rispettive feature.

### 2026-10-22 — Leggibilità callback bootstrap (completata)

- Richiesta utente: collocare la documentazione dei parametri fuori dalle chiamate concatenate in bootstrap/app.php. Nel file corrente i tag inline sono già assenti; estrarre i due callback in variabili documentate prima della dichiarazione, preservando configurazione e ordine middleware. Nessuna operazione Git/gh.

- Estratti configureMiddleware e configureExceptions prima della catena Application; entrambi hanno il proprio PHPDoc con @param prima della dichiarazione. Nessun tag negli argomenti delle chiamate; configurazione invariata.
- Verifiche superate: php -l, PHP-CS-Fixer dry-run su 119 file, Larastan livello 8 senza errori e 17 test mirati/88 asserzioni. Log /private/tmp/signalbox-bootstrap-readability.log e signalbox-bootstrap-readability-analyse.log. Suite completa/frontend non ripetuti per il refactoring circoscritto dei callback PHP. Prossimo task invariato M1.05.

### 2026-10-22 — M1.05: matrice parametrica degli ingressi (completato)

- Richiesta utente: procedere con il prossimo task sul nuovo branch. Dipendenze M1.01–M1.04 soddisfatte secondo il registro; nessuna operazione Git/gh.
- Piano: catalogo verificato delle rotte team/inviti e test parametrizzati per quattro ruoli, team estraneo e membership revocata; ID annidati di altri team, richieste guest/non verificate, stato persistito immutato sui rifiuti e assenza di notifiche. Verificare proiezioni account/elenco, cambio team e capability che non autorizza il team; mantenere i test di scope/worker/concorrenza esistenti. Formatter, Larastan, test mirati e verifica completa; aggiornare documentazione e criteri di uscita M1 con risultati reali.

- Implementazione conclusa senza modifiche al codice applicativo: TeamRouteMatrix dichiara nove rotte private e nove ingressi account/capability; TeamAuthorizationFixture costruisce due aggregati disgiunti, attori con quattro ruoli e ID annidati reali, snapshot raw del database test senza credenziali account. TeamAuthorizationMatrixTest verifica l’inventario registrato, i verbi e i middleware, fallendo su rotte di dominio non classificate.
- Matrice principale: nove rotte × quattro ruoli × membership valida/team estraneo/membership revocata = 108 casi; 18 casi per guest e non verificato. Tutti i rifiuti verificano stato persistito immutato e notifiche assenti; i successi verificano modifiche effettive, proiezioni consentite e aggregato estraneo invariato. Campi team/attore contraffatti non sostituiscono contesto o identità autenticata; contesto privato sempre ripulito.
- Gerarchia: 80 casi attore/ruolo target su update/remove membri e create/resend/revoke inviti; altri 16 casi sul ruolo richiesto per una membership member esistente. Target owner non principale e altro owner disponibile evitano che un invariante di proprietà nasconda l’autorizzazione. Restano le regressioni M1.03 su ultimo/titolare owner e la contesa PostgreSQL.
- Ulteriori casi: ID figli di altri team sotto URL accessibile, successore owner estraneo, elenchi/account senza dati estranei per i quattro ruoli e preferenza revocata, ID account query ignorati, cambio owner-A → viewer-B senza trasferire privilegi, capability valida senza accesso privato prima dell’accettazione, ID/token di team diversi e credenziali invito nel payload senza continuazione provata. Verificati anche guest/verification gate degli ingressi account e logout dell’invitato non verificato senza adesione implicita.
- Copertura nuova: **254 casi/2124 asserzioni** oltre la baseline M1.04. Test mirati intermedi con --fail-on-skipped --fail-on-warning: 246 casi/2082 asserzioni superati; gli otto casi finali sono inclusi nella verifica completa. Nessun difetto applicativo rilevato e nessun comportamento o permesso di prodotto modificato. Test con tempo congelato, fake mail/cache di test e PostgreSQL isolato; nessuna rete di consegna reale.
- Verifiche finali: `./scripts/verify.sh` exit 0, PHP 8.4.26, PHP-CS-Fixer dry-run su **122 file**, Larastan livello 8 zero errori, **449 test Pest/3163 asserzioni** tutti superati, ESLint/Prettier/TypeScript, 5 test qualità, **20 test DOM** e build Vite. Inclusi scope, binding, job serializzati sul worker Redis reale, CLI, auth/2FA e test concorrenti precedenti. Log /private/tmp/signalbox-m1.05-targeted.log, signalbox-m1.05-format.log e signalbox-m1.05-verify.log.
- Documentazione: nuovo AUTHORIZATION-MATRIX con tabella rotte, condizioni dei fixture, collegamento ai test precedenti e regola di estensione per nuove risorse/ingressi; aggiornati TENANCY, TEAMS, TESTING, IDENTITY, ARCHITECTURE, README e TASKS. Nessun manifest, lockfile, migration o configurazione applicativa modificati.
- Limiti: nessuna nuova CI remota/PHP 8.5 integrata, browser end-to-end, benchmark o audit completo dichiarato. API tenant, canali privati di prodotto, ricerca/cache di risorse, status pubblici e job di monitoraggio non ancora implementati restano alle proprie milestone: la matrice verrà estesa allora. Nessuna operazione Git/gh, modifica .env/chiavi, migration/seeding sul database di sviluppo, reset/riavvio applicativo. Servizi test isolati lasciati attivi.
- M1.05 e milestone M1 completati nei percorsi disponibili. Prossimo task pronto **M2.01**: modellare Monitor con enum, configurazioni tipizzate HTTP/TLS/DNS/heartbeat/keyword, regioni, pause e campi di scheduling, rispettando ADR-003/006/007/010; nessun anticipo di CRUD/checker/SSRF oltre il perimetro dei task M2 successivi.

- Verifica documentale finale: otto Markdown, 80 collegamenti locali validi, newline/spazi finali e coerenza checklist M1.05 completo/M2.01 non avviato verificati. Nessuna modifica al codice dopo la verifica completa.

### 2026-10-22 — M2.01: modello Monitor e configurazioni (completato)

- Piano autorizzato: primo task M2, colonne dedicate e JSON mirati, regione predefinita eu. Dipendenze M1 e ADR-002/003/006/007/010 soddisfatte. Nessuna operazione Git/gh.
- Implementare Monitor tenant, enum/DTO immutabili per cinque tipi, mapping esplicito delle configurazioni senza persistenza implicita, migration PostgreSQL con vincoli/indici e campi operativi protetti. URL/header/body cifrati e nascosti; pausa volontaria distinta dalla sospensione di piano, stato unknown e pianificazione inizialmente nulla.
- Factory senza creazione implicita di team, test unitari e PostgreSQL per configurazioni, cifratura, rollback, relazioni e isolamento; formatter/Larastan/test mirati e verifica completa. Form Request, SSRF, CRUD/quote/UI e checker restano nei task proprietari.

- Implementazione conclusa: Monitor con BelongsToTeam, relazione Team::monitors(), sei enum di monitoraggio, cinque DTO readonly con contratto MonitorConfiguration, mapper dedicato alle colonne e MonitorRegions/cast per selezione eu/us canonica. Solo name mass-assignable; proprietà, configurazione e stato operativo richiedono assegnazioni esplicite. I DTO applicano invarianti strutturali senza I/O; HTTP e keyword condividono impostazioni tramite composizione.
- Migration incrementale 2026_10_22_000006: colonne dello schema estese per TLS/DNS/heartbeat, JSONB per regioni/valori DNS, URL/header/body cifrati con cast Laravel e storage text. Tutti i campi di configurazione esclusi dalla serializzazione automatica. Registrata la scelta esplicita di headers_json logicamente JSON ma fisicamente text per ciphertext, senza modificare tacitamente la specifica. FK team con cascade, indice tenant/ID e indice parziale dei prossimi check non in pausa/sospesi.
- PostgreSQL verifica tipi/stati/metodi/famiglie DNS, nome non vuoto, timing/soglia positivi, contatore non negativo, regioni canoniche e campi pertinenti obbligatori/estranei null; controllati anche JSON DNS non vuoti con soli valori stringa non vuoti. Regole di URL/header/destinazioni, massimi operativi e piano appartengono ai task successivi. Stato iniziale unknown, contatore zero e timestamp null; pausa e sospensione piano indipendenti. Nessuno scheduler, prenotazione o check introdotto.
- configuration() ricostruisce il DTO e applyConfiguration() cambia soltanto gli attributi di configurazione, ripulendo i campi del vecchio tipo senza salvataggio implicito. Non modifica stato osservato, contatori regionali o scheduling: le future action di modifica ne applicheranno la semantica. Date normalizzate UTC prima della formattazione Eloquent per non perdere offset, con test al cambio DST. La factory richiede contesto anche per make(), offre cinque varianti e non crea team; timing 300s/5000ms soltanto fixture.
- Test nuovi: **89 casi/340 asserzioni** oltre la baseline, con DTO/invarianti, cinque round trip e sostituzioni, null/empty, ciphertext reale/serializzazione, vincoli SQL invalidi, regioni, UTC/DST, relazioni e scritture tra tenant, mass assignment, factory, indici, rollback e cascade. Test rollback identità aggiornato per rimuovere prima la migration dipendente Monitor e ricrearla dopo teams; preservati utenti esistenti e copertura precedente, rimossi PHPDoc locali soltanto nel blocco modificato.
- Controlli superati: test mirati --fail-on-skipped --fail-on-warning, **119 test/470 asserzioni** inclusa TeamIdentity. `./scripts/verify.sh` exit 0, PHP 8.4.26, PHP-CS-Fixer dry-run su **143 file**, Larastan livello 8 zero errori, **538 test Pest/3503 asserzioni**, ESLint/Prettier/TypeScript, 5 test qualità, **20 test DOM** e build Vite. Incluse tutte le regressioni M1 e la matrice HTTP attuale; nessuna nuova rotta da classificare in M2.01.
- Correzioni durante verifica: i validatori di array ricevono esplicitamente valori misti e producono proprietà validate, evitando PHPDoc che dichiarassero già certi i tipi da controllare; corretta annotazione generica del cast senza baseline/soppressioni Larastan. Ispezione Eloquent ha richiesto normalizzazione UTC prima della scrittura dei timestamp. Verifiche finali successive a tutte le correzioni.
- File/documenti: nuovi enum/contratto/DTO/cast/mapper/model/factory/migration, fixture configurazioni e due suite; aggiornati Team e test rollback TeamIdentity. Nuovo MONITORS; aggiornati README, ARCHITECTURE, TENANCY, TESTING, BOOTSTRAP e TASKS. Nessun manifest, lockfile, pacchetto, rotta o controller aggiunto/modificato.
- Log locali: /private/tmp/signalbox-m2.01-analyse.log, signalbox-m2.01-targeted.log e signalbox-m2.01-verify.log. Nessuna nuova CI remota o esecuzione integrata PHP 8.5, browser end-to-end, traffico di check o benchmark dichiarata. Nessuna operazione Git/gh, modifica .env/chiavi, migration/seeding sul database di sviluppo o reset/riavvio applicativo. Usati solo servizi test isolati, lasciati attivi; applicare la nuova migration deliberatamente all’ambiente di sviluppo scelto quando necessario.
- M2.01 completato; M2 complessiva resta aperta. Prossimo task **M2.02**: Form Request e validazione per tipo. M2.03 resta necessario per SSRF/redazione/segreti prima di qualsiasi I/O; M2.04 autorizzazioni/CRUD/transizioni/quote concorrenti, M2.05 UI/ricerca e M2.06 copertura integrata. Nessuna di queste funzionalità è dichiarata implementata.

- Verifica documentale finale: 7 Markdown, 85 collegamenti locali validi, newline/spazi finali e checklist M2.01 completo/M2.02 non avviato verificati. Nessuna modifica al codice dopo la verifica completa.

### 2026-10-22 — M2.02: validazione input e patch (completato)

- Piano autorizzato dall’utente: profilo prudente (timeout 100–10000 ms, body 64 KiB, soglia 1–10, intervallo 30–86400 s), patch dello stesso tipo preservano gli omessi; cambio tipo ricostruisce configurazione specifica conservando i campi comuni omessi. Dipendenza M2.01 soddisfatta; nessuna operazione Git/gh.
- Implementare Form Request Store/Update fail-closed tramite Gate, servizio di normalizzazione/validazione riusabile e risultato tipizzato con patch esplicita separata; nessuna persistenza o I/O. Proteggere stringhe significative dai middleware e impedire flash/echo dei campi sensibili. Test regole per cinque tipi, limiti, patch e HTTP/autorizzazioni/isolamento; documentazione e verifica completa. CRUD/policy effettive, quote e SSRF restano ai task successivi.

- Implementati: MonitorInput/MonitorInputPatch/ValidatedMonitorInput; MonitorInputLimits, MonitorInputSyntax e MonitorInputValidator; MonitorInputRequest, StoreMonitorRequest e UpdateMonitorRequest; MonitorInputTransport e configurazione bootstrap; config/monitor-input.php. Normalizzazione locale IDNA/IP/URL/DNS/header, stringhe esatte per body/keyword/TXT, limiti in byte e unità esplicite. Nessuna nuova dipendenza, migration o rotta applicativa.
- Semantica: validazione della configurazione effettiva dopo merge; headers/liste sostituiti interamente, null/empty distinti, campi sconosciuti/riservati/irrilevanti rifiutati anche null. Il risultato separa candidato completo privato e patch esplicita; M2.04 dovrà rivalidare quest’ultima contro snapshot fresco sotto lock. Nessun merge dei vecchi segreti nella request o persistenza del candidato anticipato.
- Adattamento HTTP: autorizzazione verificato+contesto+Gate prima di caricare configurazioni; update vincolato al monitor del tenant corrente. Policy assente nega; grant/rotte soltanto nei test. Eccezione TrimStrings/ConvertEmptyStringsToNull limitata ai path numerici monitor POST/PUT/PATCH, inclusi ID con zeri iniziali; altri ingressi invariati. Errori JSON senza echo di valori e redirect web senza input in sessione; dontFlash esteso ai campi privati noti.
- Test aggiunti: MonitorInputSyntaxTest, MonitorInputValidationTest, MonitorInputRequestTest e fixture MonitorInputFixture/MonitorRequestFixture. Cinque tipi, errori/range/limiti byte, IDNA/IP/DNS, keyword/TXT/body/header esatti, aggiornamenti parziali PUT/PATCH, cambio tipo, clear/replace, patch contro snapshot cambiato, dinieghi prima della decifratura, binding tenant e nessuna persistenza. Corretti durante lo sviluppo binding posizionale della fixture, gestione host con label vuote e import del test privacy; nessun errore residuo nei mirati.
- Verifica mirata superata: formatter, Larastan livello 8 senza errori, **142 test/293 asserzioni**, con --fail-on-skipped --fail-on-warning. Verifica completa `./scripts/verify.sh` **exit 0**: PHP 8.4.26, PHP-CS-Fixer dry-run su **159 file**, Larastan livello 8 senza errori, **680 test Pest/3796 asserzioni**, ESLint/Prettier/TypeScript, **5 test qualità**, **20 test DOM** e build Vite. Incluse regressioni M1, matrice HTTP e modello Monitor.
- Documentazione: [MONITOR-INPUT.md](MONITOR-INPUT.md), MONITORS, ARCHITECTURE, TESTING, BOOTSTRAP e README aggiornati. Il validatore controlla sintassi senza risolvere nomi o contattare rete: destinazioni private strutturalmente valide restano da autorizzare in M2.03. Quote/policy/CRUD/transizioni M2.04, UI M2.05 e integrazione completa M2.06 restano aperti, così come tutte le milestone M3–M5.
- Limiti operativi: nessuna nuova CI remota o esecuzione integrata PHP 8.5 dichiarata; nessuna modifica .env/chiavi, migration sul database di sviluppo o riavvio/reset dei processi applicativi. Usati soltanto servizi test isolati; nessuna operazione Git/gh. Nessuna verifica di destinazioni reali o prestazioni di rete, estranee al task.
- M2.02 completato; M2 complessiva resta aperta. Prossimo task pronto **M2.03**: protezioni SSRF condivise, destinazioni/resolver/indirizzi risolti/redirect e gestione segreti/redazione prima di qualsiasi I/O. I vincoli prudenziali autorizzati e la normalizzazione MX/TXT sono documentati nel contratto input; nessuna riduzione tacita dello scope.
- Verifica documentale finale: 7 Markdown, 90 collegamenti locali validi, newline/spazi finali e checklist M2.02 completo/M2.03 aperto verificati; M3–M5 conservate. Nessuna modifica al codice dopo la verifica completa.

### 2026-10-22 — M2.03: protezioni SSRF e redazione (completato)

- Piano autorizzato dall'utente con tre scelte: controllo al salvataggio solo locale, eccezioni CIDR vuote di default, redazione di tutti i valori query. Dipendenze M2.01/M2.02 soddisfatte; nessuna operazione Git/gh.
- Implementati: OutboundAddressPolicy (confronto su byte, blocklist IPv4, IPv6 solo 2000::/3 meno 2001::/23, 2001:db8::/32 e 3fff::/20, mapped come IPv4, 6to4 con IPv4 incorporato, eccezioni validate), contratto HostResolver con SystemHostResolver (A/AAAA, nome assoluto) e binding, OutboundDestinationGuard (URL, endpoint TLS, resolver DNS; tutti gli indirizzi devono essere ammessi), AuthorizedDestination con debug senza URL, OutboundRejection/OutboundDestinationRejected con messaggi privi di destinazioni, GuardedHttpOptions (redirect disabilitati, proxy d'ambiente esclusi, protocolli HTTP/HTTPS, CURLOPT_RESOLVE sugli indirizzi validati), RedirectPolicy/RedirectStep (riferimenti relativi, limite 5 hop, rifiuto downgrade, nessun header configurato cross-origin).
- Segreti: SecretRedactor per URL/header/body/testo/contesto; SecretRedactionProcessor agganciato via tap RedactSecrets a tutti i canali di log concreti; MonitorConfigurationProjection come unica proiezione esportabile; `#[\SensitiveParameter]` su URL/header/body/patch e nei servizi di sicurezza. Nuova config `config/outbound-security.php` e variabile `OUTBOUND_ALLOWED_CIDRS` in `.env.example`.
- Validatore: rifiuto senza rete di nomi locali e IP letterali bloccati per URL HTTP/keyword, host TLS e resolver DNS, con errore generico senza eco. Aggiornato un caso esistente che usava un resolver di documentazione (2001:db8::53 → 2620:fe::fe), ora correttamente bloccato.
- Test nuovi: OutboundAddressPolicyTest, OutboundDestinationGuardTest, SecretRedactorTest, GuardedHttpPinningTest (server PHP reale su loopback, nome noto solo al resolver finto, HTTP_PROXY impostato; senza eccezione rifiuto prima della connessione) e casi aggiuntivi in MonitorInputValidationTest/MonitorInputRequestTest. Correzione durante lo sviluppo: il test di pinning richiedeva `Http::allowStrayRequests()` limitato all'origine sintetica.
- Verifiche: test mirati con --fail-on-skipped --fail-on-warning superati; PHP-CS-Fixer senza modifiche; Larastan livello 8 zero errori. `./scripts/verify.sh` **exit 0**: PHP 8.4.26, formatter dry-run su **179 file**, Larastan livello 8 senza errori, **806 test Pest/4018 asserzioni** (+126/+222 rispetto a M2.02), ESLint/Prettier/TypeScript, **5 test qualità**, **20 test DOM** e build Vite. Log locale nella directory temporanea utente: signalbox-m2.03-verify.log.
- Documentazione: nuovo [OUTBOUND-SECURITY.md](OUTBOUND-SECURITY.md); aggiornati MONITOR-INPUT, MONITORS, ARCHITECTURE, TESTING, BOOTSTRAP, README e TASKS. Nessun manifest, lockfile, migration o rotta di produzione modificati.
- Limiti: nessun trasporto/checker reale (M3.03/M3.04), nessun webhook (M5); regole method/body dei redirect, pinning TLS con SNI e uso di `message()` nei `failed()` restano ai rispettivi task. `dns_get_record()` senza timeout proprio. Gli oggetti eccezione nei log non vengono riscritti. Nessuna nuova CI remota/PHP 8.5, nessuna risoluzione DNS reale nei test, nessuna modifica .env/chiavi, migration o riavvio dei processi applicativi.
- M2.03 completato; M2 resta aperta. Prossimo task **M2.04**: CRUD, pause/ripresa, policy e limiti iniziali, riusando il validatore (con controllo locale SSRF) e la proiezione sicura per le risposte.

### 2026-10-22 — M2.04: CRUD, pausa/ripresa, policy e limiti (completato)

- Piano autorizzato dall'utente con quattro scelte (permessi, hard delete, piano effettivo provvisorio, redirect+flash) sul nuovo branch creato dall'utente. Dipendenze M2.01–M2.03 soddisfatte; nessuna operazione Git/gh.
- Implementati: `config/plans.php` (matrice ADR-010 completa), PlanLimits (valori mancanti/non positivi rifiutati), TeamEntitlement, MonitorLimits (quota con monitor in pausa/sospesi, minimo intervallo, posizione deterministica created_at→ID), MonitorPolicy, action ManageMonitors (create/update/delete/pause/resume con lock Team→Monitor, riautorizzazione e rivalidazione della patch esplicita sullo snapshot bloccato), MonitorController e `routes/monitors.php` con cinque rotte. Stato iniziale unknown e slot nullo; reset dell'osservazione al cambio di configurazione e dello slot al cambio di intervallo/tipo; pausa/ripresa idempotenti e indipendenti dalla sospensione di piano; ripresa soggetta a quota e intervallo del piano corrente.
- Test: nuovi PlanLimitsTest e MonitorCrudTest (inclusa contesa cross-sessione PostgreSQL con `55P03` e riconteggio che rifiuta il sesto monitor Free); matrice di autorizzazione estesa alle cinque rotte con snapshot della tabella monitors e ID figli estranei; MonitorInputRequestTest adattato alla policy reale (viewer negato, rimosse le Gate fittizie ormai inefficaci); TeamFactory `trialEnded()`. Correzione durante lo sviluppo: precisione al secondo di `next_check_at` nel test di conservazione dello slot.
- Verifiche: test mirati superati; PHP-CS-Fixer senza modifiche; Larastan livello 8 zero errori. `./scripts/verify.sh` **exit 0**: PHP 8.4.26, formatter dry-run su **189 file**, Larastan livello 8 senza errori, **922 test Pest/4974 asserzioni** (+116/+956 rispetto a M2.03), ESLint/Prettier/TypeScript, **5 test qualità**, **20 test DOM** e build Vite. Log locale nella directory temporanea utente: signalbox-m2.04-verify.log.
- Documentazione: nuovo [MONITOR-CRUD.md](MONITOR-CRUD.md); aggiornati AUTHORIZATION-MATRIX, MONITORS, MONITOR-INPUT, TENANCY, IDENTITY, ARCHITECTURE, TESTING, BOOTSTRAP, README e TASKS. Nessuna migration, dipendenza o variabile d'ambiente aggiunta.
- Limiti: nessuna pagina/ricerca (M2.05) né test UI concorrente del sesto monitor (M2.06); nessuno scheduler/slot (M3.01), evento/broadcast (M6), stato Stripe o riconciliazione/ripresa di piano (M9), API (M10). Gli errori di limite sollevati dall'action possono flashare campi non privati (es. nome) tramite l'handler Laravel, mentre configurazioni e header restano esclusi da `dontFlash`. Nessuna nuova CI remota/PHP 8.5, nessuna modifica .env/chiavi, migration o riavvio dei processi applicativi.
- M2.04 completato; M2 resta aperta. Prossimo task **M2.05**: UI lista/dettaglio/form con proiezione sicura, ricerca Scout tenant-filtrata, filtri, paginazione, stati vuoti/errore e accessibilità.

### 2026-10-23 — M2.05: UI lista/dettaglio/form, ricerca, filtri, paginazione (completato)

- Piano approvato dall'utente sul nuovo branch creato dall'utente; dipendenze M2.01–M2.04 soddisfatte. Nessuna operazione Git/gh.
- Scelte utente: ricerca Scout su `name`, `tls_host`, `dns_name` (colonne in chiaro; URL/header/body cifrati esclusi, nessuna migration); create reindirizza al dettaglio e delete alla lista, update/pause/resume restano `back()`; filtri tipo, stato osservato e stato operativo (nessun filtro regione).
- Piano: rotte GET index/create/show/edit con controller di lettura dedicato, servizio di elenco con filtro tenant esplicito ed escape dei caratteri jolly, proiezioni sicure, quota informativa, pagine React accessibili con stati vuoti/errore/caricamento, form per tipo senza ripopolare i segreti in modifica; test PostgreSQL, matrice di autorizzazione estesa e test DOM.

- Implementati: `MonitorPageController` (index/create/show/edit con `Gate::authorize`), quattro rotte GET in `routes/monitors.php` con `NoStoreAuthentication`, `ListMonitorsRequest` (parsing tollerante per chiave), DTO `MonitorListFilter`/`MonitorUsage`, enum `MonitorOperationalState`, servizio `MonitorListing` (Scout database con predicato `team_id` esplicito, escape LIKE, ordinamento nome→ID, 20 per pagina), `MonitorPresenter` (riepiloghi senza colonne di configurazione, dettaglio solo tramite `MonitorConfigurationProjection`, opzioni enum, default del form), `MonitorLimits::usage()`, `Monitor` Searchable con `toSearchableArray()` sulle tre colonne in chiaro. Redirect create/delete e messaggi flash `monitor-*` in `HandleInertiaRequests`.
- Frontend: pagine `Monitors/Index|Create|Edit|Show`, componenti `components/monitors/` (tipi, etichette, badge testuali, paginazione, URL, `formData` puro, `MonitorForm` condiviso), opzione `wide` di `AuthLayout`, link «Monitors» nella pagina team. In modifica URL/header/body mai prepopolati e inviati solo con «Replace» esplicito o al cambio tipo.
- Test: nuovi MonitorPagesTest (18 casi/471 asserzioni), MonitorListingTest in Feature (16 casi; spostato da Unit perché il model richiede il container), Monitors.test.tsx (11 test DOM); matrice estesa a quattro rotte GET con proiezioni Inertia e ID figli estranei per show/edit; MonitorCrudTest adattato ai nuovi redirect. Correzioni durante lo sviluppo: etichetta DNS ambigua («Name» → «Query name»), pagine allineate alla convenzione `usePage()` per la tipizzazione di `renderInertia`, focus del primo errore testato con frame differiti, cast TypeScript superflui rimossi.
- Verifiche: test mirati matrice+CRUD 419 passati, MonitorPagesTest 18 passati, DOM 11 passati; PHP-CS-Fixer 0 modifiche su 198 file; Larastan livello 8 senza errori. `./scripts/verify.sh` **exit 0**: PHP 8.4.26, formatter dry-run su **198 file**, Larastan senza errori, **1014 test Pest/6220 asserzioni** (+92/+1246 rispetto a M2.04), ESLint/Prettier/TypeScript, **5 test qualità**, **31 test DOM** (+11), build Vite; zero skip. Log nella scratchpad di sessione (m2.05-verify.log).
- Documentazione: nuovo [MONITOR-UI.md](MONITOR-UI.md); aggiornati MONITOR-CRUD, MONITORS, AUTHORIZATION-MATRIX, TENANCY, ARCHITECTURE, TESTING, BOOTSTRAP, README e TASKS. Nessuna migration, dipendenza o variabile d'ambiente.
- Limiti: nessun test browser end-to-end né prova manuale su `http://127.0.0.1:8000` (richiederebbe la migration monitors sul database di sviluppo, non eseguita); sesto monitor concorrente via UI resta M2.06; date nel locale del browser (timezone del team con M6); nessuna colonna destinazione in lista per non decifrare gli URL. Nessuna nuova CI remota/PHP 8.5, nessuna modifica .env/chiavi, migration o riavvio dei processi; nessuna operazione Git/gh.
- M2.05 completato; M2 resta aperta. Prossimo task **M2.06**: copertura integrata di tutti i tipi, input errati, permessi e isolamento, con il sesto monitor Free rifiutato via UI anche con richieste concorrenti.

### 2026-10-23 — M2.06: copertura integrata e sesto monitor concorrente via UI (completato)

- Piano approvato dall'utente sul nuovo branch creato dall'utente; dipendenze M2.01–M2.05 soddisfatte. Nessuna operazione Git/gh.
- Scelte utente: concorrenza verificata con server HTTP reale (`artisan serve` multi-worker sul database di test isolato) e POST Inertia paralleli via Guzzle, senza Playwright né nuove dipendenze; contratto frontend↔backend con fixture JSON condivisa verificata da Vitest e inviata da Pest alla rotta di produzione.
- Implementati (solo test e supporto di test, nessuna modifica al codice di produzione):
  - `Tests\Support\IsolatedHttpServer`: avvio del CMD del Dockerfile con N worker sui servizi di test isolati, login reale e header Inertia; ContainerWebEnvironmentTest ora usa questo helper.
  - `Tests\Support\CommittedTeamFixture`: team Free con trial scaduto, owner con password sintetica e monitor seed creati con l'action, scritti su una seconda connessione committata e con `cleanup()` esplicito.
  - Nuovi MonitorQuotaConcurrencyTest (2), MonitorLifecycleTest (40 casi: ciclo di vita dei cinque tipi, 23 input errati in creazione e 5 in modifica, viewer per tipo, isolamento della ricerca su host TLS e nome DNS condivisi, default del contratto), MonitorPayloadContract.test.ts (8) e un test DOM sull'input conservato dopo il rifiuto di quota.
- Correzioni durante lo sviluppo, tutte nei test:
  - `pg_stat_activity` è fotografato per transazione, quindi serve `pg_stat_clear_snapshot()` a ogni sondaggio;
  - il conteggio va fatto dalla connessione di test, che non vede la quinta creazione non committata;
  - un datasource Vitest saltato è stato sostituito da casi espliciti, per mantenere zero skip;
  - le sessioni in-process non sopravvivono tra due richieste di test, quindi gli errori nelle props della pagina successiva si verificano sul server reale;
  - i dati del team estraneo restituiscono 404, come nella matrice.
- Verifiche:
  - MonitorQuotaConcurrencyTest ripetuto 3 volte, sempre 2/2 verdi;
  - MonitorLifecycleTest 40 passati;
  - PHP-CS-Fixer 0 modifiche su 202 file; Larastan livello 8 senza errori.
  - `./scripts/verify.sh` **exit 0**: PHP 8.4.26, formatter dry-run su **202 file**, Larastan senza errori, **1056 test Pest/6924 asserzioni** (+42/+704 rispetto a M2.05), ESLint/Prettier/TypeScript, **5 test qualità**, **40 test DOM** (+9), build Vite; zero skip.
  - Dopo la suite, nessuna riga `quota-%` residua in teams o users del database di test.
  - Log nella scratchpad di sessione (m2.06-verify.log).
- Documentazione: MONITOR-UI (sezione «Integrated coverage (M2.06)», con la mappa criterio → test e la forma del JSON), TESTING, MONITOR-CRUD, MONITORS, AUTHORIZATION-MATRIX, ARCHITECTURE, BOOTSTRAP, README e TASKS. Nessuna migration, dipendenza o variabile d'ambiente.
- Limiti:
  - nessun browser reale (Playwright), per scelta dell'utente;
  - la raffica parallela non dimostra la serializzazione da sola, che è provata dal caso deterministico;
  - la suite PHP resta sequenziale, e il server isolato usa sessioni cookie, cache array e queue sync;
  - nessuna prova manuale su `http://127.0.0.1:8000` e nessuna operazione sul database di sviluppo;
  - nessuna nuova CI remota/PHP 8.5;
  - nessuna operazione Git/gh.
- M2.06 e M2 completati. Prossimo task **M3.01**: `monitors:dispatch`, jitter deterministico, selezione chunkById e avanzamento temporale senza deriva.

### 2026-10-23 — M2.06: correzione CI backend-php8.4

- Segnalazione dell'utente: il job `backend-php8.4` è fallito con 12 test di MonitorLifecycleTest in errore («Not a valid Inertia response» e `assertOk` fallito sulle pagine).
- Causa verificata riproducendo il job in locale (cartella vuota montata su `public/build`): il job backend non esegue la build Vite, quindi le pagine HTML falliscono con `ViteManifestNotFoundException`. MonitorPagesTest e la matrice usano già `withoutVite()`, MonitorLifecycleTest no; in locale la build esistente nascondeva l'errore. Correzione: `withoutVite()` nel `beforeEach`.
- Secondo difetto emerso durante la riproduzione: `IsolatedHttpServer::stop()` terminava `artisan serve` ma non il master `php -S` né i suoi worker. I processi orfani tenevano la porta e lo stdout della pipe, e un'esecuzione con output in pipe restava bloccata dopo la fine di Pest. Correzione: `stop()` raccoglie i discendenti da `/proc` prima dell'arresto e li termina dopo.
- Verifiche:
  - suite Pest completa senza build Vite, come in CI: **1056 passati/6924 asserzioni**, exit 0;
  - test dei server isolati: 3 passati e **0 processi `php -S` residui**, contro i 9 osservati prima della correzione.
  - La conferma della CI remota resta a carico dell'utente dopo il push.

### 2026-10-23 — MA: pannello di amministrazione piattaforma (completato)

- Piano approvato dall'utente; decisione di scope registrata (milestone MA fuori specifica). Dipendenze M1/M2 soddisfatte. Nessuna operazione Git/gh; nessuna migration sul database di sviluppo senza consenso.
- Scelte utente: super admin come flag su database concesso solo da CLI (scartata l'email in env); password recente sulle modifiche e 2FA obbligatoria solo in produzione; override test illimitato e piano omaggio Pro/Business con scadenza opzionale; Horizon unificato al ruolo di piattaforma.
- Implementati:
  - Database: migration `users.is_platform_admin`, `platform_audit_events` (append-only) e `team_plan_overrides` (vincoli tipo/piano e motivo).
  - Accesso: comando `signalbox:platform-admin` (grant/revoke/list), `PlatformAccess` e gate `accessPlatformAdmin` con flag riletto e 2FA in produzione; `viewHorizon` delegato al gate, `HORIZON_ADMIN_EMAILS` rimossa da config, `.env.example` e `phpunit.xml`.
  - Entitlement: `Plan::Internal` con profilo finito in `config/plans.php`, `TeamEntitlement::resolve()` con DTO `Entitlement` ed enum `EntitlementSource`.
  - Override e pannello: action `ManagePlanOverrides` sotto lock Team, `EnsurePlatformAdmin` (404 ai non operatori), servizio `PlatformTeamDirectory`, presenter, request, controller e `routes/admin.php`; pagine React `Admin/Teams/Index|Show`, link «Admin» e prop `auth.user.isPlatformAdmin`.
- Test: nuovi PlatformAdminAccessTest (31), PlatformAdminCommandTest (7), PlanOverrideTest (17), Admin.test.tsx (6); PlanLimitsTest con il profilo internal.
  - Adeguamenti di test esistenti: discovery della matrice tenant che esclude i controller Admin; inversione delle migration in TeamIdentityTest che include `team_plan_overrides`; proiezione `auth.user` in AuthenticationTest con `isPlatformAdmin`; test Horizon spostato dal Feature senza database all'Integration, perché il gate legge il flag.
  - Correzioni durante lo sviluppo, tutte nei test:
    - precisione al secondo dei timestamp;
    - conferma password calcolata con `now()` sotto `travelTo`;
    - fixture committata per il test di contesa;
    - confronto delle chiavi jsonb indipendente dall'ordine.
- Verifiche:
  - test mirati superati; PHP-CS-Fixer 0 modifiche su 226 file; Larastan livello 8 senza errori;
  - suite Pest completa senza build Vite: **1111 passati/7228 asserzioni**, 0 processi `php -S` residui;
  - `./scripts/verify.sh` **exit 0**: formatter dry-run su **226 file**, Larastan senza errori, **1111 test Pest/7228 asserzioni** (+55/+304 rispetto a M2.06), ESLint/Prettier/TypeScript, **5 test qualità**, **46 test DOM** (+6), build Vite; zero skip.
  - Log nella scratchpad di sessione (ma-verify.log).
- Documentazione: nuovo [PLATFORM-ADMIN.md](PLATFORM-ADMIN.md) e ADR-011 in ARCHITECTURE; aggiornati SERVICES, BOOTSTRAP, MONITOR-CRUD, AUTHORIZATION-MATRIX, TESTING, README e TASKS (note di compatibilità in M9.04/M9.05).
- Limiti:
  - gestione utenti e impersonificazione non implementate (MA.05/MA.06);
  - alla scadenza di un override i monitor eccedenti non vengono sospesi (M9.05);
  - nessuna prova nel browser, perché le migration non sono applicate al database di sviluppo senza consenso;
  - `HORIZON_ADMIN_EMAILS` non è presente nel `.env` locale;
  - nessuna nuova CI remota/PHP 8.5;
  - nessuna operazione Git/gh.
- Prossimo task funzionale: **M3.01**.

### 2026-10-23 — Strumento locale: pgAdmin opzionale

- Richiesta utente: aggiungere pgAdmin come servizio accessibile solo da localhost. Nessuna operazione Git/gh; `.env` locale non modificato.
- Implementati:
  - servizio `pgadmin` in `compose.yaml`: profilo `dbadmin`, `127.0.0.1:${PGADMIN_PORT:-5050}`, volume `pgadmin-data`, upgrade check disattivato, dipendenza dal solo `postgres` di sviluppo;
  - `docker/pgadmin/servers.json` con il server preregistrato, senza password;
  - variabili `PGADMIN_EMAIL`/`PGADMIN_PASSWORD`/`PGADMIN_PORT` in `.env.example`, vuote.
- Verifiche:
  - `docker compose config --quiet` superato, con e senza profilo;
  - avvio di prova con credenziali temporanee passate solo nell'ambiente della shell: `/misc/ping` e `/login` HTTP 200, porta pubblicata soltanto su `127.0.0.1:5050`, server importato («Added 1 Server Group(s) and 1 Server(s)»), release 9.18, `postgres:5432` raggiungibile dal container;
  - container e volume di prova rimossi dopo la verifica.
- Non verificati: login e connessione dall'interfaccia con la password reale del database (servono le credenziali dell'utente nel `.env`).
- Documentazione: DOCKER (sezione «Optional pgAdmin», con i campi del JSON) e DEPENDENCIES.

### 2026-10-23 — Aggiornamento di sicurezza PHP 8.4.27

- Richiesta utente: aggiornamento immediato da PHP 8.4.26 a 8.4.27 per una vulnerabilità grave. Rilascio verificato su php.net: 8.4.27 del 21-22 ottobre 2026, con correzioni del core GHSA-w8hw-w9fx-j79w e GHSA-6wmf-4rv5-p3rp e vari use-after-free.
- Modifica: in `docker/php/Dockerfile` l'immagine base passa da `php:8.4-cli-bookworm@sha256:836ac6c6…` a `php:8.4.27-cli-bookworm@sha256:496b5e02…`, con il tag patch esplicito e un commento con gli advisory.
- Verifiche:
  - immagine `signalbox-php:local` ricostruita: `php -v` 8.4.27, estensioni bcmath/intl/mbstring/pcntl/pdo_pgsql/zip presenti;
  - `./scripts/verify.sh` **exit 0** con «PHP runtime: 8.4.27»: formatter su 226 file senza modifiche, Larastan senza errori, **1111 test Pest/7228 asserzioni**, frontend, 5 test qualità, 46 test DOM, build.
- Container di sviluppo `web`, `queue` e `scheduler` ricreati con la nuova immagine, su richiesta esplicita di aggiornamento immediato; volumi invariati. Tutti riportano 8.4.27, `/up` HTTP 200, Horizon running.
- Limiti:
  - la CI usa setup-php con `'8.4'`: la patch effettiva va letta dal log del job dopo il push;
  - il PHP 8.5.9 dell'host non è stato toccato;
  - nessuna operazione Git/gh.
- Documentazione: DEPENDENCIES e DOCKER.

### 2026-10-23 — Chiusura sessione

- Attivazione della CI chiarita: `pull_request` scatta solo per PR aperte e `push` solo per staging/main, quindi un branch con PR già mergiata non avvia workflow. L'utente ha usato un nuovo branch con nuova PR verso staging: CI verde secondo l'utente. Workflow invariato; scelta e alternative scartate documentate in [CI.md](CI.md).
- Documentazione aggiornata: CI (evento e procedura), TASKS (consegna di sessione e ripartenza). Nessuna modifica al codice in questo passaggio; nessuna operazione Git/gh dell'agente.



### 2026-10-23 — M3.01–M3.02: scheduling e consegna recuperabile (completati)

- Piano approvato: intervento congiunto M3.01/M3.02, dipendenza M2 soddisfatta. MA.05/MA.06 rinviati.
- Registro transazionale, slot UTC, publisher e acquisizione con fencing; consumer di test fino al runner applicativo. Flusso disabilitato per default, attivazione differita ai task del runner/checker.
- Decisione implementativa: revisione semantica positiva dei monitor per invalidare i lavori vecchi, mantenuta dal CRUD sotto lock; evita hash dei ciphertext che cambiano anche per un cambio nome. Pausa/ripresa invalida il lavoro precedente; downgrade persistito e cleanup del registro restano nei task proprietari.
- Primo passaggio completo: 1156 test Pest passati e 2 falliti nei test storici di rollback (MonitorModel/TeamIdentity), perché la nuova FK richiede di invertire prima la migration delle esecuzioni. Corretti entrambi seguendo l’ordine reale; suite pertinenti: 87 passati/418 asserzioni. La successiva verifica completa è passata, come riportato sotto.
- File applicativi: nuovi servizi `app/Services/Monitoring/Scheduling/`, contratti `CheckWorkPublisher`/`CheckWorkConsumer`, modello `CheckExecution`, job `DeliverCheckExecution`, comandi `DispatchMonitors`/`PublishMonitorChecks`/`RecoverMonitorChecks`, `config/monitor-scheduling.php` e migration `2026_10_23_000010_create_check_executions_table.php`; aggiornati `Monitor`, `ManageMonitors`, `MonitorLimits`, `AppServiceProvider`, `routes/console.php` e `.env.example`.
- Test: nuovi `MonitorSlotsTest`, `MonitorSchedulingConfigurationTest`, `MonitorSchedulingTest`, `MonitorSchedulingConcurrencyTest`, `RecordingCheckPublisher` e probe `RunSchedulingProbe`; adattati i rollback in `MonitorModelTest` e `TeamIdentityTest` alle nuove dipendenze. Le gare usano due processi PHP reali con barriera PostgreSQL; consumer di test sulle code Redis regionali, mai traffico ai target.
- Verifiche finali: `./scripts/verify.sh` **exit 0**, PHP **8.4.27**, PHP-CS-Fixer dry-run su **248 file** (0 modifiche), Larastan livello 8 senza errori, **1158 test Pest/7444 asserzioni** (+47/+216 rispetto alla baseline), ESLint/Prettier/TypeScript, **5 test qualità**, **46 test DOM** e build Vite. Log: `/tmp/signalbox-m3-verify-final.log`. Test mirati scheduling+CRUD: 80 passati/447 asserzioni; rollback/model/identity: 87 passati/418 asserzioni.
- Documentazione: nuovo [MONITOR-SCHEDULING.md](MONITOR-SCHEDULING.md); aggiornati ARCHITECTURE, MONITORS, MONITOR-CRUD, TENANCY, TESTING, BOOTSTRAP, README e TASKS. Nessuna nuova dipendenza o modifica ai lockfile.
- Limiti espliciti: gate `MONITOR_SCHEDULING_ENABLED=false` di default, consumer applicativo non ancora associato; nessuna esecuzione reale dei checker, CheckResult, persistenza incidenti, benchmark o prova nel browser. Lease 10 s, ripubblicazione dopo 5 s, timeout del job 30 s e deadline persistita 35 s rispetto a Redis retry_after 90 s. Fencing e recupero verificati con consumer di test; integrazione del runner, budget dei trasporti, `WithoutOverlapping` e `failed()` restano M3.03–M3.08. La cancellazione dello storico del registro resta lavoro di lifecycle futuro. Nessuna nuova CI remota o verifica PHP 8.5 dichiarata.
- Nessuna operazione Git/gh, nessuna modifica al `.env`, nessuna migration o riavvio dei processi di sviluppo. La nuova migration va applicata deliberatamente prima di usare il CRUD aggiornato sul database di sviluppo; mantenere disabilitato il motore fino al runner reale.
- **M3.01 e M3.02 completati** nel perimetro concordato, con attivazione differita. M3 resta aperta. Prossimo task pronto: **M3.03**, contratto e strategie HTTP/keyword; MA.05/MA.06 rinviati.


### 2026-10-23 — M3.03: checker HTTP e keyword (completato)

- Piano approvato: DTO/contesto/esiti normalizzati, evaluator Strategy HTTP e keyword, trasporto condiviso `Http::pool()`, budget di rete comune ai redirect e cap di 1 MiB decompressi per osservazione. Batch massimi di 10 ingressi.
- Decisione di scope esplicita dell'utente: misurare la latenza senza aggiungere ora la soglia citata nella specifica; soglia rinviata, non rimossa dalla specifica.
- Nessuna attivazione dello scheduler o associazione del consumer applicativo; runner, CheckResult e incidenti restano nei task proprietari. DNS conserva il limite noto del resolver attuale, da affrontare con l'adapter M3.04.
- File applicativi: nuovi DTO in `app/Data/Monitoring/Checks/`, `CheckError`, contratti `CheckClock`/`HttpResponseStrategy`, servizi `app/Services/Monitoring/Checks/` e `config/monitor-checks.php`; aggiornati `AppServiceProvider` e `GuardedHttpOptions` (connessioni nuove senza riuso per rispettare il pin corrente).
- Scelte implementative: evaluator puri separati dall'acquisizione concorrente; risoluzione esplicita dei tipi; filtro PHP di scrittura sul sink per contare byte già decompressi e interrompere davvero l'overflow, anche nei fake. I timeout riusano `MonitorInputLimits`; il tempo monotono misura solo le fasi di rete, escludendo DNS e attesa di altri risultati del pool.
- Test nuovi: `HttpCheckStrategyTest`, `HttpCheckCoordinatorTest`, `HttpCheckTransportTest`, fixture `CheckInputFixture`/`FakeCheckClock` e server non bloccante `CheckLoopbackServer`. Test mirati: **62 passati/284 asserzioni**, inclusa barriera su due socket reali, overflow gzip decompresso e un target in timeout accanto a uno sano. Nessuna rete esterna.
- Documentazione: nuovo [HTTP-CHECKERS.md](HTTP-CHECKERS.md), aggiornati ARCHITECTURE, BOOTSTRAP, TESTING, MONITORS, MONITOR-SCHEDULING, OUTBOUND-SECURITY, README e TASKS. Nessuna nuova dipendenza, modifica ai lockfile o nuova variabile d'ambiente.
- Verifica completa: `./scripts/verify.sh` **exit 0** con PHP **8.4.27**; PHP-CS-Fixer dry-run su **272 file**, 0 modifiche; Larastan livello 8 senza errori; **1220 test Pest/7728 asserzioni** (+62/+284 rispetto alla baseline M3.01–M3.02); ESLint/Prettier/TypeScript, **5 test qualità**, **46 test DOM** e build Vite. Log: `/tmp/signalbox-m3-03-verify.log`.
- Limiti espliciti: acquisizione a round concorrenti, senza promessa di ritorno anticipato degli esiti o benchmark; il cap riguarda i body decompressi, non gli header. DNS resta sincrono e senza timeout applicativo: nessuna garanzia di deadline wall-clock dell'intera osservazione. Soglia di latenza rinviata per decisione dell'utente. Nessun checker TLS/DNS/heartbeat, risultato persistito, incidente, binding del consumer o attivazione del motore; nessuna nuova CI remota/PHP 8.5, prova nel browser o rete esterna.
- Nessuna operazione Git/gh, migration, modifica `.env` o riavvio dei processi di sviluppo.
- **M3.03 completato**. M3 resta aperta. Prossimo task pronto: **M3.04**, strategie TLS/DNS e adapter sostituibili; affrontare anche il limite di risoluzione DNS del guard HTTP. MA.05/MA.06 restano rinviati.
- Messaggio di commit suggerito: `feat(monitoring): add HTTP and keyword checkers`.


### 2026-10-23 — Chiusura della sessione dopo M3.03

- L’utente conferma risolta la segnalazione sul push/banner GitHub e chiede di chiudere la sessione.
- Aggiornato soltanto `docs/TASKS.md` con consegna di ripartenza, limiti, verifiche già eseguite e preferenza per messaggi di commit brevi. Nessun controllo remoto o nuova verifica del codice dichiarato.
- Prossimo task **M3.04**; MA.05/MA.06 rinviati. Nessuna operazione Git/gh, migration o modifica dell’ambiente.


### 2026-10-26 — M3.04: checker TLS/DNS e risoluzione limitata (completato)

- Piano approvato e implementato con dipendenze M2/M3.01–M3.03 soddisfatte. Perimetro limitato ad acquisizione TLS/DNS, valutazione e risoluzione del guard HTTP; heartbeat, runner, risultati e attivazione restano nei task successivi.
- Decisioni utente: confronto DNS per insieme esatto ignorando ordine/duplicati/TTL; resolver di sistema fidato quando non specificato; esito down alla soglia inclusiva del preavviso TLS. Null verifica la sola validità corrente; zero non anticipa la scadenza.
- File applicativi nuovi: contratti `DnsTransport`/`TlsTransport`, eccezione `CheckTransportFailed`, DTO `AsyncCheckCompletion`, servizi `AsyncCheckRuntime`, `DnsNameserver`, `DnsLookup`, `ReactDnsTransport`, `PinnedTcpConnector`, `ReactTlsTransport`, `DnsStrategy`, `TlsStrategy` e `NetworkCheckCoordinator`. Aggiornati `HostResolver`, `SystemHostResolver`, `OutboundDestinationGuard`, `HttpTransportErrors`, `CheckInput`/`CheckOutcome`, `CheckError`, `AppServiceProvider`, `config/monitor-checks.php`, manifest e lock Composer.
- TLS: A/AAAA concorrenti, rifiuto se qualsiasi IP è bloccato, prima destinazione autorizzata senza retry, nome originale per SNI/verifica, catena/hostname/validità obbligatori. Timeout completo DNS/TCP/handshake; metadato nullable UTC `tlsExpiresAt` nell’esito, senza aggiornamento del monitor.
- DNS: cinque famiglie, nomi assoluti e dati normalizzati con TXT esatto; controllo identità della domanda, sole risposte pertinenti, CNAME diretto per quel tipo e alias fino a otto passaggi per gli altri. UDP con continuazione TCP solo per troncamento, stesso timeout, niente retry/cache/hosts/search domain o fallback pubblico. Resolver custom autorizzato su porta 53; i valori DNS sono dati, non destinazioni da contattare.
- Risoluzione HTTP: sostituito `dns_get_record()` con A/AAAA concorrenti e deadline complessiva configurabile, default 1000 ms per lookup inclusi gli alias. Nessuna autorizzazione di risultati parziali se una famiglia fallisce. Latenza e budget HTTP restano di rete, distinti dal DNS; nessuna promessa di deadline complessiva HTTP.
- Deviazione motivata dal piano: un coordinatore condiviso per TLS/DNS evita duplicazione di ammissione tenant, concorrenza, deadline e failure mapping; strategie e trasporti restano separati. Runtime su loop privato con completamento tipizzato e cancellazione dei lavori pendenti, senza annotazioni locali o soppressioni Larastan.
- Composer: `ext-openssl` e cinque componenti ReactPHP già presenti nel lock diventano dipendenze dirette. `composer update --lock --no-install --no-scripts --no-audit --no-interaction` riporta nessuna versione da modificare; `composer validate --strict --no-check-publish` passa. Nessuna modifica npm, Docker o stack.
- Test nuovi: `NetworkCheckStrategyTest`, `NetworkCheckCoordinatorTest`, `NetworkCheckTransportTest`; fake DNS/TLS e fixture `TlsCertificateFixture`, `TlsLoopbackServer`, `DnsLoopbackServer`. **69 test mirati/198 asserzioni** passati: regole pure, tenant, batch prima dell’I/O, alias/cicli, NXDOMAIN/SERVFAIL, payload malformati, timeout, cancellazione e regressione HTTP; TLS reale con CA sintetica, hostname/catena/scadenza errati, TCP rifiutato e handshake bloccato, DNS UDP/TCP e barriere di concorrenza. Nessuna rete esterna. Certificati e chiavi sintetici temporanei rimossi con i server figli.
- Nei primi passaggi Larastan ha rilevato inferenze generiche e forme MX non correttamente espresse; risolte con un completamento tipizzato e normalizzazione al confine mixed, senza abbassare il livello di analisi. Formatter applicato e successivo dry-run superato.
- Verifica finale: `./scripts/verify.sh` **exit 0**, PHP **8.4.27**, PHP-CS-Fixer dry-run su **293 file**, 0 modifiche; Larastan livello 8 senza errori; **1289 test Pest/7926 asserzioni** (+69/+198 rispetto a M3.03); ESLint/Prettier/TypeScript, **5 test qualità**, **46 test DOM** e build Vite. Log: `/tmp/signalbox-m3-04-verify.log`.
- Documentazione: nuovo [TLS-DNS-CHECKERS.md](TLS-DNS-CHECKERS.md); aggiornati ARCHITECTURE, BOOTSTRAP, DEPENDENCIES, TESTING, MONITORS, MONITOR-SCHEDULING, HTTP-CHECKERS, OUTBOUND-SECURITY, README e TASKS, compreso il riferimento obsoleto a M3.01 nell’ordine operativo.
- Limiti: nessun risultato persistito, riepilogo monitor o incidente; consumer applicativo non associato e scheduling disabilitato. Nessuna migration o modifica a `.env`/chiavi/processi/database di sviluppo; test sui servizi isolati. Nessuna nuova CI remota/PHP 8.5 integrata, browser o benchmark dichiarati; nessuna operazione Git/gh dell’agente.
- **M3.04 completato**, M3 resta aperta. Prossimo task pronto: **M3.05**, heartbeat. MA.05/MA.06 e soglia di latenza rinviati come concordato.
- Messaggio di commit suggerito: `feat(monitoring): add TLS and DNS checkers`.


### 2026-10-26 — M3.05: heartbeat (completato)

- Piano approvato: POST Bearer, finestra mobile, arming immediato e UI minima con token una sola volta. Pausa o inammissibilità del piano restituiscono 204 senza registrazione. Dipendenze M2 e M3.01–M3.04 soddisfatte.
- Persistenza tenant di stato/ricezioni e strategia allo slot di riferimento implementate; runner, CheckResult, incidenti e attivazione restano successivi.
- Git/gh riservati all’utente; nessuna migration o modifica all’ambiente di sviluppo.

- Persistenza e confini: nuova migration con `monitor_heartbeats` e `heartbeat_receipts`, FK composite tenant, generazione positiva, digest SHA-256 univoco e timestamp UTC a microsecondi; stato corrente con TeamScope e storico indicizzato/deduplicato per generazione/istante. Backfill dei monitor heartbeat esistenti alla migration, senza segreti o guasti retroattivi. Cleanup storico registrato in M7.04; riarmo nelle future riprese di piano richiamato in M9.05.
- File applicativi: nuovi `MonitorHeartbeat`, `HeartbeatLifecycle`, `HeartbeatLimits`, action `ReceiveHeartbeat`/`ManageHeartbeatCredentials`, `HeartbeatRateLimited`, DTO `HeartbeatSnapshot`, servizi `HeartbeatStrategy`/`HeartbeatSnapshotReader`, request/controller/middleware dedicati, `routes/heartbeats.php` e `config/heartbeat.php`. Aggiornati `ManageMonitors`, factory monitor, `CheckError`, presenter, bootstrap e rotte monitor. Nessun nuovo pacchetto o modifica ai lockfile.
- Sicurezza e concorrenza: discovery dal digest limitata alle identità, tenant derivato dal record e rivalidazione sotto lock Team → Monitor → stato; eligibility centralizzata per pausa/piano/quota/cadenza. Token 256 bit mai memorizzati in chiaro, 404 uniforme per invalidi/revocati, 204 senza registrazione per inattivi, limiti 600/IP/minuto e 120/credenziale/minuto con 429 e Retry-After. Ingresso esterno fuori da sessione/CSRF; mutazioni web ancora protette da CSRF reale e policy update.
- Semantica temporale: finestra mobile da attivazione o ultima ricezione visibile allo slot, down da uguaglianza alla scadenza inclusa la tolleranza. Ricezioni tardive non riparano slot precedenti; resume effettivo e modifiche intervallo/grace avviano una nuova generazione. Cambio nome, resume ripetuto e rotazione token non riarmano; uscita dal tipo e delete invalidano credenziali e rimuovono lo storico associato. Nessun aggiornamento diretto a risultati, riepiloghi monitor o incidenti.
- UI: nuovo `HeartbeatPanel.tsx`, tipi e dettaglio aggiornati. Metadati non segreti per viewer, gestione per owner/admin/member, conferme, stati caricamento/errore, curl copiabile e token effimero via fetch JSON no-store/CSRF. Nessun token in props/history/session flash/storage/URL; hide, cambio monitor/permessi e unmount cancellano la visualizzazione e annullano le richieste pendenti.
- Test: nuovi `HeartbeatStrategyTest`, `HeartbeatTest`, `HeartbeatConcurrencyTest` e `HeartbeatPanel.test.tsx`; estesi TeamRouteMatrix, TeamAuthorizationFixture e matrice parametrica a tutte le nuove rotte e ai relativi effetti. Adattati i rollback MonitorModel/TeamIdentity alla nuova dipendenza. Prove con HTTP reale e barriera PostgreSQL per ricezioni, rotazione, pausa/nuova generazione e CSRF; server e fixture sintetici ripuliti, nessuna rete esterna.
- Correzioni durante le verifiche: adeguate fixture trial non-null e reset della chiave IP test; la barriera per due ricezioni ora usa due listener distinti per garantire processi separati, mantenendo l’osservazione dei lock reali. Risolti controlli Larastan sui docblock di eccezioni dei callback e lint/formato frontend. La suite completa ha intercettato il catalogo centralizzato delle nuove rotte: esteso esplicitamente, senza esclusioni o riduzione dei gate; **31 casi mirati/383 asserzioni** passati prima del nuovo passaggio completo.
- Verifica finale: `./scripts/verify.sh` **exit 0**, PHP **8.4.27**, formatter dry-run su **312 file** con 0 modifiche, Larastan livello 8 senza errori, **1359 test Pest/8387 asserzioni** (+70/+461 rispetto a M3.04), ESLint/Prettier/TypeScript, **5 test qualità**, **53 test DOM** e build Vite. Log: `/tmp/signalbox-m3-05-verify.log`.
- Documentazione: nuovo [HEARTBEATS.md](HEARTBEATS.md); aggiornati ARCHITECTURE, BOOTSTRAP, DEPENDENCIES, MONITORS, MONITOR-CRUD, MONITOR-UI, MONITOR-SCHEDULING, HTTP-CHECKERS, TLS-DNS-CHECKERS, TENANCY, OUTBOUND-SECURITY, AUTHORIZATION-MATRIX, TESTING, README e TASKS.
- Limiti: migration non applicata al database di sviluppo dall’agente; va applicata deliberatamente prima dell’uso del CRUD aggiornato. Nessuna modifica `.env`/chiavi/processi di sviluppo; nessuna nuova CI remota, integrazione PHP 8.5, browser E2E o benchmark dichiarati. Motore disabilitato e consumer non associato fino ai task runner/risultati. Git/gh non eseguiti dall’agente.
- **M3.05 completato**, M3 resta aperta. Prossimo task pronto: **M3.06**; M3.07 per CheckResult/partizioni. Nessuna riduzione di M3/M4/M5; MA.05/MA.06 e soglia di latenza restano rinviati.
- Messaggio di commit suggerito: `feat(monitoring): add dedicated heartbeat monitoring`.


### 2026-10-26 — Regressione CI HeartbeatTest (corretta e verificata localmente)

- Segnalazione utente dopo il push; log remoto richiesto, nessun accesso Git/gh dell’agente. Riprodotto localmente il primo test senza asset Vite: atteso 200, ricevuto 409 a HeartbeatTest:68.
- Causa verificata: middleware.version restituisce null senza manifest, mentre Inertia normalizza la versione server a stringa vuota. Il test invia invece un header esplicitamente null, che non coincide con la versione server.
- Corretto l’header del test con `version(...) ?? ''`, coerente con la normalizzazione server di Inertia. Aggiunto dataset con versione corrente e public path temporaneo vuoto, verificando davvero il ritorno null del middleware senza mock di classi final. La directory viene rimossa alla distruzione dell’istanza applicativa; asset di sviluppo non rinominati o cancellati.
- Verifiche finali su PHP 8.4.27: HeartbeatTest **24 passati/121 asserzioni** con build disponibile e **24 passati/121 asserzioni** con build coperta nel solo container da una directory vuota. PHP-CS-Fixer dry-run su **312 file**, 0 modifiche. Prima della correzione il caso senza build falliva riproducibilmente con 409.
- File modificati: `tests/Integration/HeartbeatTest.php`, `docs/TESTING.md`, `docs/TASKS.md`. Nessun cambiamento applicativo, CI, dipendenze, migration o ambiente di sviluppo. Suite globale, frontend e Larastan non ripetuti: la correzione riguarda soltanto il test e la documentazione, e i test non rientrano nei percorsi Larastan. I risultati completi della consegna M3.05 restano storici, non sono una nuova esecuzione.
- Log remoto non ancora fornito; la causa verificata localmente corrisponde a un 409 sulla prima verifica di pagina HeartbeatTest. Nessuna dichiarazione di CI remota verde o verifica PHP 8.5. Git/gh non eseguiti; prossimo passo: commit/push dell’utente e verifica del nuovo job.
- Messaggio di commit suggerito: `test: fix heartbeat Inertia version without built assets`.


### 2026-10-26 — M3.06–M3.07: runner e risultati completati

- Piano congiunto approvato; dipendenze M3.01–M3.05 soddisfatte. Lotti regionali di due esecuzioni, timeout 45 s, deadline 50 s, lock 55 s, Redis retry_after 90 s. Risultati mensili UTC sullo slot, istante osservato separato e transizioni atomiche con fence.
- Consumer reale, fallimenti riconciliabili e riepiloghi con watermark; nessuna attivazione del motore, migration o modifica dei processi/database di sviluppo. M3.08, incidenti e retention restano successivi. Verifiche completate, con evidenze qui e nella consegna in testa al documento; Git/gh riservati all’utente.

- Implementazione: `RunMonitorChecks`/`PreparedCheck` catturano configurazione e heartbeat nella transazione di acquisizione; pool HTTP/keyword e coordinatore TLS/DNS lavorano dopo il commit. `DeliverCheckBatch`, envelope singolo compatibile, publisher e binding del provider integrano code regionali, lock ordinati e hook `failed()` ricreato da Laravel con UUID/tentativo persistiti.
- `ExecutionLedger` e `ExecutionResults` salvano transizione, CheckResult e riepilogo nella stessa transazione. FK composita inclusiva della revisione, unicità sullo slot, trigger di immutabilità e watermark impediscono scritture duplicate, reidentificazione tra mesi, contaminazione tenant e regressione dei riepiloghi. Solo osservazioni reali hanno up/down e checked_at; esiti mancati/cancellati/infrastrutturali restano unknown.
- `ResultPartitions`, migration, comando `monitors:partitions` giornaliero e preparazione on demand creano il mese UTC corrente e i due successivi con advisory lock e verifica dei bounds. Nessuna pulizia o backfill dei vecchi terminali; M7 deve conciliare retention misurata sull’osservazione con partizioni dello slot, anche quando il check termina nel mese successivo.
- Decisioni: compatibilità del vecchio messaggio singolo mantenuta; un vecchio `finish()` diretto senza outcome conserva lo stato del registro ma produce infrastruttura/unknown, senza inventare successi. Nessun retry di dominio; la ripubblicazione riguarda solo lavoro mai acquisito. Risultati di revisioni superate restano storici senza aggiornare il riepilogo. Nessuna transizione incidente o contatore di failure aggiunta.
- Test aggiunti in `MonitorRunnerTest` e `MonitorSchedulingConcurrencyTest`, fixture worker Redis con eccezione/timeout/SIGKILL, barriera HTTP reale per il lotto, contesa PostgreSQL per partizioni e recupero/hook, isolamento e identità, atomicità, ordine degli slot e confine mensile. Adeguati i test di configurazione, compatibilità e rollback delle migration. Documenti aggiornati: runner, scheduling, bootstrap e collegamenti dalle tre fondazioni checker.

- Verifiche concluse: `./scripts/verify.sh` exit 0 (1388 test/8542 asserzioni prima degli ultimi due casi); esecuzione finale backend `composer verify` senza asset Vite exit 0 con **1390 test/8550 asserzioni**, formatter su 325 file e Larastan livello 8. Frontend: lint/formato/TypeScript, 5 test qualità, 53 test DOM e build superati nella verifica completa. Le ultime modifiche riguardano PHP/test/documentazione; nessuna modifica frontend successiva. Nessuna verifica saltata nei due run.
- **M3.06 e M3.07 completati**; prossimo passo **M3.08**, estendere le sequenze e la matrice del motore senza anticipare incidenti o retention. Applicare deliberatamente la nuova migration all’ambiente scelto prima dell’uso; l’agente non ha migrato né attivato lo sviluppo e non ha eseguito Git/gh.

### 2026-10-26 — M3.08: matrice integrata del motore completata

- Piano approvato; dipendenze M3.01–M3.07 soddisfatte. Estendere sequenze complete, errori TLS/DNS, heartbeat e pause, riuso worker tra tenant, isolamento regionale e lock Redis, jitter deterministico. Nessuna nuova funzione prodotto o attivazione dello sviluppo prevista.
- Verifiche completate su servizi isolati, suite completa e backend senza asset Vite; evidenze nella consegna e nel registro. Git/gh riservati all’utente.

- Aggiunti `MonitorEngineTest` (sequenze complete per HTTP/keyword, errori e recupero TLS/DNS, sei confini heartbeat via ingresso Bearer, pause/ripresa e modifica autorizzata durante il check) e `MonitorWorkerIsolationTest` (stesso worker tra tenant con eccezione e envelope estraneo, progresso US mentre EU attende, lock Redis fra slot distinti e ripubblicazione reale).
- Estese le fixture `RunCheckWorkerProbe` e `CheckLoopbackServer`, aggiunto il supporto `EngineWorkerProbe`; i modi precedenti restano compatibili. Il server usa gate espliciti e marker di acquisizione; il worker restituisce UUID/PID, pulizia contesto e conteggio tentativi senza configurazione privata. `MonitorSlotsTest` verifica una coorte fissa di 6000 ID su intervalli 30/60/300/47, fusi e salti di 1000 periodi, senza attribuirle misure di throughput.
- Decisioni: riutilizzati i test di trasporto/terminazione/concorrenza precedenti dove coprono già il criterio; matrice requisito→test in `docs/TESTING.md` e collegamento nel documento runner. Nessuna modifica al codice applicativo, schema, dipendenze, lockfile o flussi prodotto.
- Prima regressione mirata: 63 test/631 asserzioni superati con `--fail-on-skipped`, Larastan senza errori. Ultima revisione solo di leggibilità/cleanup e verifica esplicita dell’evento di skip; suite completa in esecuzione.

- Verifiche finali: `./scripts/verify.sh` **exit 0** su PHP **8.4.27**, PHP-CS-Fixer dry-run su **328 file**, Larastan livello 8, **1411 test Pest/8991 asserzioni**, lint/formato/TypeScript, **5 test qualità**, **53 test DOM** e build. Log `/tmp/signalbox-m308-verify.log`.
- Ripetuta l’intera suite backend con directory vuota read-only montata su `public/build`: `docker compose run --rm -v /tmp/signalbox-heartbeat-no-assets-m8y9j5v1:/var/www/html/public/build:ro php-test composer test -- --fail-on-skipped --compact` **exit 0**, **1411 test/8991 asserzioni** senza salti. Log `/tmp/signalbox-m308-no-assets.log`. Nessun codice sorgente modificato dopo queste verifiche; completati soltanto i resoconti Markdown.
- **M3.08 completato e M3 completata.** Nessuna modifica applicativa necessaria: cambiati soltanto test, fixture e documentazione. Nessuna nuova migration o dipendenza, nessuna attivazione/modifica dello sviluppo, nessun Git/gh. CI remota, PHP 8.5 integrata, browser E2E e benchmark non eseguiti; non necessari alla verifica locale di questo task e non dichiarati come risultati. Prossimo task pronto: **M4.01**.

### 2026-10-26 — Checklist manuale browser prima di M4

- Richiesto dall’utente un documento con ambiente, ruoli, passaggi e risultati attesi. Creato `docs/MANUAL-BROWSER-TESTS.md` e collegato da `docs/TESTING.md`: preparazione locale, account/seeder e membership, dati sintetici, casi A–G con motore spento, sezione H opzionale, registro e cleanup. Ruoli, URL, codici HTTP e limiti confrontati con route, policy, servizi e documentazione correnti.
- Solo documentazione: nessun test browser eseguito, nessuna attivazione, migration, modifica applicativa o Git/gh. Suite automatica non rieseguita per queste sole modifiche Markdown; M3 resta completata e il prossimo task implementativo resta M4.01.

- Verifica documentale superata: 47 casi identificati, sezioni ambiente/ruoli/dati/registro presenti e collegamenti locali validi. I casi restano NON ESEGUITI fino alla compilazione del registro manuale.

### 2026-10-27 — Revisione bug pre-M4 e piano FX

- Richiesta utente: controllo dei potenziali bug prima di pianificare M4. Rilette scheduling, registro, runner, risultati, partizioni, heartbeat, limiti ed entitlement.
- Trovati quattro difetti, registrati come FX.01–FX.04 prima di M4. Due note di progetto sono rinviate a M4/M12. Solo analisi statica: Docker non attivo, nessun test eseguito né codice modificato. Git/gh non eseguiti.
- Prossimo passo: FX.01–FX.04 dopo l’approvazione dell’utente, poi M4.01.

### 2026-10-27 — FX.01–FX.04: correzioni implementate, verifica da completare

- Richiesta utente: correggere i quattro bug in un unico branch, da creare a cura dell’utente (`git switch -c fix/pre-m4-engine`). Nessun comando Git/gh eseguito dall’agente.
- Riproduzione prima della correzione: i nuovi test FX.01 (`lock_timeout` lasciato a 2 s dopo `ensure()`/`acquire()` annidati) e FX.04 (`ssl_expires_at` azzerato) **fallivano 7/7**. Il test FX.02 ha mostrato che una sessione senza fuso fissato prende il default del ruolo (`America/New_York`) e sposta gli istanti.
- FX.01 `ResultPartitions`: controllo rapido senza lock né DDL per i mesi esistenti; creazione con advisory lock, `CREATE TABLE IF NOT EXISTS` (un mancato riscontro può restare in cache nel catalogo durante l’attesa dell’advisory lock: emerso dal test di gara esistente e corretto) e ripristino del `lock_timeout` del chiamante.
- FX.02 `config/database.php`: `'timezone' => 'UTC'` sulla connessione `pgsql`. FX.03: isolamento per elemento in reserve/publish/recover con log dei soli identificativi e della classe dell’eccezione, nuovo DTO `SchedulingPass`, metodo `runPass()` e comandi che terminano con errore se qualche elemento fallisce. `run(): int` resta invariato. FX.04 `ExecutionResults`: si conserva l’ultima scadenza osservata.
- Test aggiunti: 3 casi in `MonitorRunnerTest` (+7 righe di dataset), 1 in `ServicesDatabaseTest`, 3 in `MonitorSchedulingTest` (un trigger PostgreSQL creato solo nella transazione del test fa fallire sempre un elemento). Documentazione aggiornata: MONITOR-RUNNER, MONITOR-SCHEDULING, SERVICES.
- Verifiche: PHP-CS-Fixer dry-run su **329 file**, 0 modifiche; Larastan livello 8 senza errori; **Pest 1422 test/9021 asserzioni, 0 skip**; ESLint, Prettier, TypeScript, test qualità e **53 test DOM** superati. Log nello scratchpad della sessione (`fx-verify2.log`).
- Non completato: la **build Vite** è stata interrotta dalla perdita del daemon Docker (`error waiting for container: unexpected EOF`); in seguito l’utente ha fermato Docker per liberare risorse. Il primo `./scripts/verify.sh` ha superato il `process-timeout` di 300 s di Composer: la suite oggi ha impiegato 679 s contro i 159 s del 26 ottobre, lentezza dovuta all’ambiente (stesso file con e senza la modifica di FX.02: 33 s contro 35 s; nessuna sessione PostgreSQL appesa). Nessun file frontend è stato modificato.
- Prossimo passo: con Docker attivo, eseguire `docker compose run --rm node npm run build` (o `./scripts/verify.sh` se l’ambiente torna alla velocità abituale), quindi segnare FX.01–FX.04 completati e passare a M4.01.
- Messaggio di commit suggerito: `fix(monitoring): harden partitions, UTC sessions and scheduling passes`.

### 2026-10-27 — FX.01–FX.04: verifica completata

- Ripresa dopo il riavvio di Docker da parte dell’utente. Nessun file sorgente modificato rispetto alla sessione precedente.
- `docker compose run --rm node npm run build` superata (11,2 s). Poi `./scripts/verify.sh` **exit 0** in 3 min 41 s: PHP-CS-Fixer dry-run su **329 file** con 0 modifiche, Larastan livello 8 senza errori, **Pest 1422 test/9021 asserzioni** (155,75 s, 0 skip), lint/formato/TypeScript, **5 test di qualità**, **53 test DOM** e build Vite. Log nello scratchpad della sessione (`fx-verify3.log`). Tempi tornati in linea con il 26 ottobre: la lentezza precedente dipendeva dall’ambiente.
- **FX.01–FX.04 completati.** Non eseguiti: CI remota, PHP 8.5 integrata, browser E2E. Git/gh restano all’utente.
- Prossimo passo: commit a cura dell’utente (messaggio suggerito: `fix(monitoring): harden partitions, UTC sessions and scheduling passes`), poi M4.01.

### 2026-10-28 — M4.01: modello incidenti e transizioni (completato)

- Piano approvato; dipendenze M3 e FX.01–FX.04 soddisfatte. Perimetro: schema `incidents`/`incident_updates`, enum di stato e severità, modelli, factory, regole pure di transizione, test e documentazione. Valutazione soglia/regioni (M4.02), action di apertura/chiusura/presa in carico/riapertura (M4.03), manutenzioni (M4.04) e UI/policy (M4.05) restano successive.
- Decisioni utente: stati stile status page (`investigating`/`identified`/`monitoring`/`resolved`) con presa in carico separata; severità derivata dal `CheckError` della conferma e modificabile in seguito; riapertura dello stesso incidente entro una finestra di cooldown, altrimenti nuovo incidente.
- Branch suggerito all’utente: `git switch -c feat/incident-model` da `staging`. Nessun Git/gh eseguito dall’agente.
- Implementati: `app/Enums/Incidents/IncidentStatus.php`, `IncidentSeverity.php`; `app/Models/Incident.php`, `IncidentUpdate.php`, relazione `Monitor::incidents()`; migration `2026_10_28_000013_create_incidents_tables.php` (FK composite tenant, indice `incidents_single_open`, CHECK di coerenza, trigger `guard_incident_update_change`); factory `IncidentFactory`/`IncidentUpdateFactory`; `config/incidents.php`.
- Test: `tests/Unit/IncidentStatusTest.php`, `tests/Unit/IncidentSeverityTest.php`, `tests/Integration/IncidentModelTest.php`; aggiunta la nuova migration ai test di rollback di `MonitorModelTest` e `TeamIdentityTest`. Prima esecuzione mirata: 2 errori (closure statica nello stato della factory, corretta); poi 162 test/531 asserzioni superati.
- Durante l’implementazione: la FK `author_id ON DELETE SET NULL` è un UPDATE sulla timeline, quindi il trigger consente esplicitamente il solo azzeramento dell’autore (coperto dal test di cancellazione utente).
- Documentazione: nuovo [INCIDENTS.md](INCIDENTS.md); sezioni M4.01 in ARCHITECTURE, TESTING e BOOTSTRAP.
- Verifica finale `./scripts/verify.sh` **exit 0**: formatter su 340 file, Larastan livello 8, 1497 test/9134 asserzioni con 0 skip, frontend (lint, formato, TypeScript, 5 test qualità, 53 test DOM, build).
- **M4.01 completato.** Non eseguiti: CI remota, PHP 8.5 integrata, browser E2E. Migration non applicata al database di sviluppo.
- Prossimo passo: **M4.02**. Messaggio di commit suggerito: `feat(incidents): model incidents, timeline and transitions`.

### 2026-10-28 — M4.02–M4.03: conferma regionale e ciclo automatico degli incidenti (completati)

- Piano approvato; dipendenza M4.01 soddisfatta. Decisione utente: unire M4.02 e M4.03 nello stesso branch; `monitors.consecutive_failures` rappresenta il minimo dei contatori delle regioni configurate (progresso verso la conferma).
- Perimetro: sequenze per regione sotto il lock del ledger, verdetto per slot, apertura/riapertura/risoluzione automatica con voci di sistema ed eventi dopo commit, azioni manuali (presa in carico, aggiornamenti, severità/nota, pubblicazione) con policy. Manutenzioni, rotte/UI e notifiche restano successive.
- Branch suggerito all’utente: `feat/regional-incident-confirmation`. Nessun Git/gh eseguito dall’agente.
- Implementati: `App\Contracts\Monitoring\ResultObserver` e `RecordedResult` (invocati da `ExecutionResults::record()`); `RegionalSequence`, `SlotVerdict`, `SlotVerdictKind`, `ConfirmationRule` (regole pure); `IncidentDetection`, `IncidentAutomation`, `IncidentLifecycle`; modello `MonitorRegionSequence` e migration `2026_10_28_000014_create_monitor_region_sequences.php`; eventi `IncidentOpened`/`IncidentReopened`/`IncidentResolved` (`ShouldDispatchAfterCommit`); `ManageIncidents`, `IncidentPolicy`, `IncidentChangeRejected`; binding in `AppServiceProvider`.
- Test: `RegionalSequenceTest`, `ConfirmationRuleTest` (30 casi), `IncidentDetectionTest` (11, ledger reale con orologio fissato), `ManageIncidentsTest` (13); rollback di `MonitorModelTest`/`TeamIdentityTest` estesi alla nuova migration. Suite del motore esistenti verdi con l’observer attivo (223 test nella regressione mirata).
- Difetti emersi dai test e corretti: la risoluzione manuale restituiva la prima voce della timeline invece di quella di risoluzione (ordinamento della relazione), ora `IncidentLifecycle::resolve()` restituisce la voce creata; l’helper di test alternava monitor saltando slot, comportamento corretto della sequenza, quindi i monitor sono eseguiti in sequenza.
- Deviazione dal piano: nessun nuovo test a due sessioni sulla concorrenza degli incidenti. Le due regioni dello stesso slot sono serializzate dai lock Team → Monitor del ledger, già coperti dalle suite di concorrenza del motore; l’indice `incidents_single_open` resta la garanzia finale e la conferma ripetuta è verificata idempotente. Non aggiunta la relazione `Monitor::regionSequences()`, non usata.
- Documentazione: INCIDENTS (conferma regionale, azioni manuali, test), ARCHITECTURE, TESTING, BOOTSTRAP, MONITOR-RUNNER.
- Verifica finale `./scripts/verify.sh` **exit 0**: formatter su 361 file, Larastan livello 8, 1551 test/9339 asserzioni con 0 skip, frontend completo e build.
- **M4.02 e M4.03 completati.** Non eseguiti: CI remota, PHP 8.5 integrata, browser E2E. Migration non applicata al database di sviluppo.
- Prossimo passo: **M4.04**. Messaggio di commit suggerito: `feat(incidents): confirm regional failures and open, resolve and reopen incidents automatically`.
