# AGENTS.md — Signalbox

## Scopo e fonti

Signalbox è un SaaS multi-tenant di monitoraggio applicativo, incident management e status page con abbonamenti. Questo file guida tutte le sessioni di lavoro nel repository.

- Specifica di prodotto: `docs/03-Progetto-B-Signalbox-Laravel.md`.
- Piano operativo e stato del lavoro: `docs/TASKS.md`.
- Matrice verificata, versioni risolte e documentazione dei manifest JSON: `docs/DEPENDENCIES.md`.
- Bootstrap e documentazione delle configurazioni iniziali: `docs/BOOTSTRAP.md`.
- Leggere questi riferimenti prima di iniziare; rispettare le istruzioni esplicite dell'utente. Registrare decisioni, deviazioni e loro motivazioni nel piano; non modificare tacitamente la specifica.
- Non implementare funzioni estranee al perimetro. Non eliminare M3, M4 o M5. Eventuali riduzioni di dominio personalizzato, PDF e portale clienti richiedono una decisione di scope registrata.

## Gestione Git riservata all’utente

- Tutti i comandi Git vengono eseguiti esclusivamente dall’utente, inclusi quelli di sola lettura (`status`, `diff`, `log`, ecc.). L’agente non deve eseguire comandi Git, direttamente, tramite script o tramite altri strumenti.
- L’agente può scrivere e spiegare i comandi da eseguire, ma deve lasciare all’utente creazione/cambio/eliminazione dei branch, staging, commit, fetch, pull, push, merge, rebase e ogni altra operazione Git.
- Anche i comandi GitHub CLI (`gh`) e le operazioni equivalenti sul repository remoto o sulle pull request sono riservati all’utente. Non creare, modificare o integrare PR autonomamente.
- Non modificare direttamente `.git` o usare librerie/API per aggirare queste regole. Quando servono informazioni sullo stato del repository, chiedere all’utente l’output dei comandi pertinenti.

## Flusso di ogni sessione

1. Leggere questo file, la specifica, lo stato dei task e le note dell'ultima sessione.
2. Scegliere un task pronto, controllarne le dipendenze e segnarlo in corso prima delle modifiche.
3. Implementare una porzione verificabile, includendo documentazione e test automatici.
4. Eseguire formatter, analisi statica e test pertinenti; correggere gli errori introdotti.
5. Aggiornare `docs/TASKS.md` con stato, file interessati, verifiche e risultati, decisioni, blocchi e prossimo passo. Non segnare completo un task con criteri ancora insoddisfatti.
6. Riportare chiaramente verifiche non eseguite e relativi motivi. Non inventare risultati o metriche.

## Stack e compatibilità

Seguire la specifica: PHP 8.4 con CI anche su 8.5, Laravel 13, PostgreSQL 16, Redis 7, Horizon, Reverb, Inertia 2, React 19, TypeScript, Tailwind 4, Sanctum, Cashier/Stripe test, Scout database driver, Pest e Larastan livello 8. Docker e GitHub Actions supportano sviluppo e deploy.

Prima del bootstrap verificare sui riferimenti ufficiali la compatibilità delle versioni e il supporto JSON:API indicato nella specifica. M0.01 ha verificato Pest 4 con plugin Laravel 4 come adeguamento necessario a Laravel 13 (Pest/plugin 3 esclusi); mantenere i vincoli e i lockfile verificati in `docs/DEPENDENCIES.md`. Per i successivi aggiornamenti motivare e registrare ogni adeguamento, senza sostituire silenziosamente lo stack. SSR e Meilisearch sono opzionali; SMS è uno stub e le regioni sono simulate.

## Architettura e qualità del codice

- Applicare tutti i principi SOLID: responsabilità singola, apertura all'estensione, sostituibilità, segregazione delle interfacce e inversione delle dipendenze.
- Utilizzare design pattern moderni appropriati al problema, evitando astrazioni speculative. Preferire Strategy per i checker, adapter per servizi esterni, policy per autorizzazioni e limiti, eventi per effetti disaccoppiati; documentare la motivazione delle scelte.
- Controller e job orchestrano; le regole di dominio risiedono in servizi/action coesi, verificabili e indipendenti dai dettagli di trasporto. Utilizzare dependency injection e contratti dove esistono confini o varianti reali.
- Usare tipi espliciti, enum e DTO dove utili; validare gli input con Form Request. Evitare duplicazioni, dipendenze circolari e classi con responsabilità eccessive.
- Progettare transazioni, concorrenza, lock e idempotenza in modo esplicito; un evento o webhook duplicato non deve duplicare effetti di dominio.
- Tenere componenti React e hook coesi, con TypeScript rigoroso e stati di caricamento, errore e vuoto; rispettare accessibilità e navigazione da tastiera.

## Documentazione obbligatoria in ogni file

- Tutti i commenti, docblock, PHPDoc, TSDoc/JSDoc e annotazioni esplicative nel codice devono essere in inglese. Devono spiegare cosa viene implementato, responsabilità, significato dei dati, vincoli e scelte rilevanti.
- Ogni file sorgente deve documentare il proprio scopo. Ogni classe, interfaccia, trait, enum, componente, hook, funzione, metodo, proprietà, costante, parametro e variabile, incluse quelle locali, deve avere documentazione pertinente.
- Per PHP usare PHPDoc con descrizione e tag appropriati (`@param`, `@return`, `@throws`, `@var`); per TypeScript usare TSDoc/JSDoc e commenti locali. Documentare unità di misura, nullabilità, forme dei dati, effetti collaterali e invarianti quando pertinenti.
- Anche test, migration, factory, seeder, script e configurazioni che consentono commenti seguono queste regole. Non aggiungere commenti a JSON o ad altri formati che non li supportano: documentarne gli elementi in un documento Markdown associato e collegato.
- Aggiornare i commenti insieme al codice; non lasciare descrizioni generiche, obsolete o segnaposto. La documentazione non sostituisce nomi chiari e tipi corretti.
- I documenti di pianificazione possono restare in italiano; la regola dell'inglese riguarda tutti i commenti del codice.

## Test e controlli automatici

- Implementare test automatici per ogni comportamento e correzione: unit test per regole pure, test feature/integration per database, HTTP, code, autorizzazioni e servizi integrati; test frontend per interazioni significative.
- Usare Pest, fake HTTP/code/notifiche e controllo del tempo; evitare rete reale e attese nei test ordinari. Testare le caratteristiche PostgreSQL su PostgreSQL, non soltanto su SQLite.
- Coprire percorsi positivi, errori, confini temporali, concorrenza/idempotenza e isolamento tenant. Nessuna rotta, job o canale privato può esporre dati di altri team.
- Rendere obbligatorio PHP-CS-Fixer con configurazione versionata, comando di formattazione locale e controllo CI in modalità dry-run. La richiesta dell'utente prevale su Pint nella specifica: evitare due formatter PHP con regole divergenti; usare PHP-CS-Fixer come riferimento e registrare la decisione in M0.
- Configurare Larastan livello 8, controllo TypeScript, lint/format frontend, build e suite test in CI. Valutare Rector per refactoring controllati e revisionabili.
- Un task è completo solo con criteri soddisfatti, documentazione aggiornata e verifiche pertinenti superate; eventuali impedimenti restano espliciti nel piano.

## Invarianti del prodotto

- Tenancy: TeamScope, policy, ruoli owner/admin/member/viewer, token con abilità; controllare anche job, CLI, ricerca, broadcast e cache. I processi pubblici accedono soltanto a dati pubblicabili esplicitamente selezionati.
- Scheduling: jitter deterministico, dispatch a blocchi, coordinamento tra istanze e aggiornamento di `next_check_at` nella transazione di prenotazione; verificare il comportamento in caso di crash tra commit e invio in coda.
- Check: strategie HTTP/TLS/DNS/heartbeat/keyword, timeout limitati, esecuzione concorrente, code regionali `checks:eu` e `checks:us`, lock e registrazione degli errori anche in `failed()`.
- Un fallimento del check è un dato: nessun retry automatico della coda che lo nasconda. Distinguere retry di dominio, con semantica documentata, dai retry di consegna delle notifiche.
- Incidenti: soglia N, conferma regionale quando prevista, anti-flapping, transizioni coerenti e manutenzioni; nessun incidente duplicato sotto concorrenza.
- Notifiche: deduplica, escalation, quiet hours per fuso orario, manutenzioni, riepilogo di risoluzione e log di consegna; webhook firmati e segreti protetti.
- Dati: risultati partizionati per mese, rollup orari/giornalieri, retention per piano, p50/p95; definire la compatibilità tra retention grezza di 30 giorni e drop di partizioni mensili prima di implementare la pulizia.
- SLA: considerare durata degli intervalli, manutenzioni, pause e intervalli parziali; definire la semantica dei dati mancanti e testare i casi limite.
- Limiti: policy centralizzata applicata a UI, API e job; downgrade o abbonamento scaduto sospende i monitor eccedenti con criterio deterministico.
- Sicurezza: proteggere segreti, credenziali dei canali e payload sensibili; prevenire SSRF nei checker e webhook, controllare redirect/risoluzione DNS, verificare firme Stripe e autorizzazioni su ogni ingresso.

## Consegna

Prevedere demo pubblica con dati sintetici, README con i sette problemi tecnici, benchmark riproducibile con misure reali, limiti dichiarati e video di due minuti. Non usare dati reali dei clienti nella demo né commettere segreti.
