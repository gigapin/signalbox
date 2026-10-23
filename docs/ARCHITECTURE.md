# Signalbox — Decisioni architetturali

Data iniziale: 2026-10-16, M0.08. Aggiornamento: 2026-10-19, M0.09. Fonte dei requisiti: [specifica](03-Progetto-B-Signalbox-Laravel.md), [AGENTS.md](../AGENTS.md) e [piano operativo](TASKS.md). Stack e adeguamenti verificati restano in [DEPENDENCIES.md](DEPENDENCIES.md); configurazioni effettive in [BOOTSTRAP.md](BOOTSTRAP.md) e [SERVICES.md](SERVICES.md).

Questi record stabiliscono come implementare le milestone successive. Stato di tutti i record: **adottato come indirizzo architetturale; implementazione nelle milestone indicate**. Non attestano funzionalità già disponibili. A M0.08 esistono il bootstrap Laravel/Inertia, User, configurazioni dei pacchetti e infrastruttura test/CI; Team, monitor, checker, tenant context e policy dei piani devono ancora essere implementati.

## Indice dei record

| ID | Decisione | Implementazione |
|---|---|---|
| ADR-001 | Monolite modulare e responsabilità del dominio | M1–M12, progressivamente |
| ADR-002 | Strategy dei checker e adapter di trasporto | M2–M3, notifiche M5 |
| ADR-003 | Tenant context esplicito e accessi pubblici separati | M1, esteso in ogni milestone |
| ADR-004 | Autorizzazioni e limiti di piano centralizzati | M1–M2, billing M9, API M10 |
| ADR-005 | Documentazione, decisioni e verifica evolutiva | Ogni task |
| ADR-006 | Scheduler e consegna recuperabile | M2–M3, hardening M11 |
| ADR-007 | Campioni, retry e conferma regionale | M3–M4 |
| ADR-008 | Retention, rollup e percentili esatti | M3, M7–M9 |
| ADR-009 | SLA temporale e copertura | M4, M6–M8 |
| ADR-010 | Piani, trial e riconciliazione | M1–M2, M7–M10 |
| ADR-011 | Operatore di piattaforma e override di entitlement | MA, rispettato da M9 |

## ADR-001 — Monolite modulare e responsabilità del dominio

### Contesto e decisione

Il prodotto condivide dati transazionali e richiede coerenza tra monitor, incidenti, notifiche e abbonamenti. Adottiamo una sola applicazione Laravel, un frontend Inertia/React e PostgreSQL condiviso con isolamento logico per team. Web, Horizon, scheduler e Reverb sono processi della stessa applicazione; le regioni eu/us restano simulate.

I confini sono responsabilità logiche, non nuovi servizi da distribuire:

| Ambito | Dati e regole posseduti | Collaborazioni ammesse |
|---|---|---|
| Identità e tenancy | Utenti, team, membership, ruoli, inviti e selezione del team | Fornisce identità e autorizzazione agli altri ambiti |
| Monitoraggio | Configurazioni monitor, scheduling, esecuzioni, heartbeat e risultati | Usa contesto tenant e limiti; fornisce esiti agli incidenti e dati alle metriche |
| Incidenti e manutenzioni | Soglie, transizioni, timeline e finestre di manutenzione | Consuma esiti; produce eventi per notifiche e aggiornamenti pubblicabili |
| Notifiche | Canali, escalation, quiet hours, deduplica e consegne | Consuma eventi incidenti e disponibilità delle manutenzioni |
| Metriche e report | Rollup, retention, percentili, SLA e report PDF | Legge risultati, pause e manutenzioni attraverso query esplicite |
| Status page | Pagine, componenti selezionati, pubblicazione e iscrizioni | Espone solo proiezioni pubblicabili di monitor, incidenti e metriche |
| Billing e piani | Abbonamenti Team, stato del piano e riconciliazione dei limiti | Riceve webhook verificati; invoca operazioni di sospensione dei monitor |

Dashboard e API sono ingressi/proiezioni di questi ambiti: non mantengono una seconda implementazione delle regole. La presenza di una dipendenza logica non autorizza a modificare direttamente lo stato posseduto dall'altro ambito; le scritture passano dalle sue action.

Manteniamo le convenzioni Laravel esistenti: modelli in `App\Models`, policy in `App\Policies`, controller/Form Request nei namespace HTTP. Organizziamo action e servizi per ambito quando vengono introdotti, per esempio `App\Actions\Monitoring` e `App\Services\Monitoring`. Contratti, DTO, enum ed eventi seguono lo stesso ambito nei rispettivi namespace. Nessuno spostamento del bootstrap o creazione di cartelle/classi vuote è richiesto da questo record.

Controller, comandi e job traducono l'ingresso, richiedono autorizzazione e orchestrano action coese. Le regole non dipendono da Request, risposta Inertia o payload Stripe. I modelli conservano relazioni, cast e invarianti locali; transizioni con più scritture appartengono ad action con confine transazionale esplicito. Le funzioni di calcolo pure restano separabili dalla persistenza.

Usiamo dependency injection; introduciamo interfacce dove esiste una variante o un confine esterno reale. Eloquent resta la persistenza ordinaria: niente repository generico per ogni modello, service locator nel dominio o event bus personalizzato. I riferimenti tra ambiti devono evitare dipendenze circolari; un consumatore usa eventi o dati minimi espliciti invece di richiamare il produttore durante una sua transizione.

Gli eventi che avviano effetti esterni vengono pubblicati dopo il commit della transizione; il consumatore è idempotente. Questa regola evita effetti su dati annullati, ma non risolve da sola il crash tra commit e invio: il protocollo affidabile di prenotazione/invio è definito in ADR-006 (M0.09), da implementare e testare in M3. Nessuna promessa di consegna exactly-once.

### Alternative, conseguenze e verifica

- Microservizi esclusi per ora: aggiungerebbero distribuzione e consistenza remota prima di avere un carico misurato. Le action delimitano comunque responsabilità verificabili.
- Un unico servizio centrale escluso: accoppierebbe scheduling, incidenti, billing e trasporti. Un'interfaccia per ogni classe sarebbe invece un'astrazione priva di variante reale.
- Unit test per calcoli puri; feature/integration per transazioni, rollback, ingressi diversi che invocano la stessa regola e duplicazione degli effetti. Le milestone proprietarie introducono questi test insieme al comportamento, non in M0.08.

## ADR-002 — Strategy dei checker e adapter di trasporto

### Contesto e decisione

HTTP, keyword, TLS, DNS e heartbeat producono esiti confrontabili, ma hanno ingressi e trasporti diversi. In M2 definiamo configurazioni validate, enum dei tipi e DTO; in M3 introduciamo il contratto Strategy e il resolver esplicito tipo → strategia, registrato tramite dependency injection. Il resolver rifiuta tipi non supportati e configurazioni incoerenti; non usa classi ricevute dal client o un checker HTTP di ripiego.

Il contratto avrà configurazione tipizzata e un contesto di esecuzione con team, monitor, identità dell'esecuzione, regione e tempo di riferimento. Restituisce un risultato normalizzato con esito, istante osservato, latenza con unità esplicita quando misurabile, codice di stato quando applicabile e classificazione/redazione dell'errore. Un dato non misurabile resta nullable, senza inventare una latenza zero. Nomi definitivi e firma PHP vengono introdotti con i casi reali di M2/M3, non come interfacce speculative in questa sessione.

| Tipo | Responsabilità della strategia | Confine sostituibile |
|---|---|---|
| HTTP | Valutare risposta/status e configurazione della richiesta | Trasporto HTTP con timeout, limiti e controlli SSRF |
| Keyword | Valutare risposta HTTP e presenza/assenza del testo | Stesso trasporto HTTP e stessa osservazione della risposta |
| TLS | Valutare certificato, catena, hostname e scadenza | Adapter TLS e clock controllabili nei test |
| DNS | Confrontare record e risoluzione configurata | Adapter DNS con resolver e risposte sostituibili |
| Heartbeat | Valutare ricezione/scadenza rispetto al tempo di riferimento | Stato delle ricezioni persistite e clock |

HTTP e keyword condividono il trasporto/composizione, senza duplicare protezioni o forzare un'eredità tra strategie. Il coordinatore HTTP usa `Http::pool()` per richieste indipendenti e mantiene la correlazione tra richieste, esecuzioni ed esiti. Non implementiamo la concorrenza chiamando in sequenza un metodo bloccante per ogni monitor.

Heartbeat ha un ingresso push dedicato con credenziale propria e un'action per registrare la ricezione; la strategia valuta poi lo stato persistito. Non effettua una richiesta HTTP in uscita al job sorvegliato. Un'interfaccia comune dell'esito non obbliga tutti i tipi ad avere lo stesso protocollo di acquisizione.

Le strategie non aprono incidenti, non inviano notifiche e non aggiornano abbonamenti. Un'action di registrazione persiste il risultato e aggiorna lo stato necessario in transazione; il job orchestra ed espone `failed()` per registrare i fallimenti infrastrutturali osservabili con la stessa identità idempotente. Il percorso ordinario e `failed()` non devono creare due esiti per la stessa esecuzione. Arresti che non eseguono callback richiedono recupero esplicito nel protocollo M0.09/M3.

Un timeout o errore del bersaglio diventa un risultato di fallimento; i check mantengono `tries = 1`. ADR-007 (M0.09) esclude retry di dominio nella versione iniziale; non usiamo retry automatici della coda o del client HTTP per occultare un campione fallito. Le notifiche hanno invece retry di consegna espliciti in M5.

Le protezioni SSRF sono un confine condiviso da checker e webhook in uscita, con adapter specifici dove necessario. Validare il form non basta: destinazione, DNS e ogni redirect vanno controllati al momento dell'accesso; i segreti non entrano nei DTO di risposta, negli eventi pubblici o nei log. Algoritmo e casi di aggiramento sono implementati/testati in M2.03 e applicati ai trasporti M3/M5.

### Alternative, conseguenze e verifica

- Escluso un grande switch con I/O, persistenza e notifiche nel job: ogni tipo deve poter evolvere senza modificare tutte le altre responsabilità.
- Esclusa una Strategy che impone traffico in uscita anche a heartbeat; esclusa una classe di rete diversa per ogni variante HTTP senza un confine condiviso.
- Test contrattuali dei risultati e del resolver, fake HTTP/TLS/DNS, clock controllato, timeout e input errati; test PostgreSQL per esiti duplicati e concorrenza; test che un endpoint lento non serializzi il batch e che i fallimenti restino registrati. Regioni e limiti temporali operativi sono definiti nelle milestone proprietarie.

## ADR-003 — Tenant context esplicito e accessi pubblici separati

### Contesto e decisione

Un utente può appartenere a più team; processi pubblici e worker non hanno una sessione browser. In M1 introduciamo un contesto tenant limitato alla singola richiesta o unità di lavoro, privo di fallback implicito. Il contesto identifica il team; non attribuisce automaticamente un ruolo o un attore autenticato.

L'accesso ai dati privati di un team richiede un contesto valido. `TeamScope` limita i modelli di proprietà tenant e rifiuta l'accesso se il contesto manca: non deve diventare una query senza filtro. User, autenticazione e risoluzione iniziale delle membership non ricevono indiscriminatamente lo scope tenant; sono ingressi necessari per stabilirlo. Anche la ricerca dei team accessibili passa dalla membership dell'attore.

| Ingresso | Come stabilire il contesto e il permesso |
|---|---|
| Web/Inertia | Autenticare l'utente, validare la membership del team selezionato, poi eseguire binding/query tenant e policy |
| API Sanctum | Validare token, team esplicito e membership; combinare abilità con policy. Il team della sessione browser non determina silenziosamente quello della richiesta API |
| Job/listener | Serializzare team ID e identificatori minimi, stabilire il contesto prima di caricare modelli tenant e verificare che i riferimenti appartengano allo stesso team |
| CLI/scheduler | Enumerazione globale dedicata e minima dei team/lavori; ogni operazione privata successiva ha contesto esplicito |
| Heartbeat | Risolvere credenziale dedicata e monitor associato tramite ingresso ristretto; derivare il team dal record validato, mai da un team ID non verificato del chiamante |
| Stripe webhook | Verificare firma prima di usare il payload, risolvere il cliente Team tramite mapping interno; eseguire poi l'operazione nel suo contesto con idempotenza |
| Status page pubblica | Risolvere solo pagine pubblicate e usare una query/proiezione pubblica dedicata con campi e associazioni esplicitamente pubblicabili |

Il middleware del contesto deve precedere il route model binding dei modelli tenant; le policy restano necessarie anche dopo lo scope. Non serializziamo modelli Eloquent tenant nei job prima di avere un contesto che consenta la loro reidratazione. L'unità di lavoro ripulisce il contesto in `finally`, anche su errore; nessun singleton mutabile di processo o stato statico condiviso tra job. Si verifica la sequenza team A → errore → team B nello stesso worker.

Le scritture assegnano il team dal contesto verificato, non dal mass assignment del payload. Scope, relazioni, controlli e vincoli database coerenti devono impedire associazioni con record di un altro team. Query builder, SQL grezzo e join non sono protetti automaticamente dall'intenzione dello scope: richiedono predicati tenant espliciti e test dedicati.

L'enumerazione globale e la risoluzione delle credenziali esterne sono operazioni infrastrutturali ristrette: selezionano solo i dati necessari a stabilire il lavoro autorizzato. Non introduciamo un generico `withoutTenant` esposto ai controller o un parametro pubblico che disabilita lo scope. L'accesso pubblico non crea un tenant di comodo né rende pubblici tutti i dati di un team.

La chiave delle cache private include team e risorsa; i lock aggiungono l'identità dell'operazione. Cache pubbliche usano l'identità della pagina e vengono invalidate quando pubblicazione o contenuti cambiano. Ricerca Scout e query aggregate richiedono filtri tenant espliciti. Canali privati `team.{id}` richiedono membership/policy, con payload minimi inviati dopo commit; le origini Reverb non sostituiscono questi controlli. L'allowlist operatori Horizon resta separata dai ruoli dei team.

### Alternative, conseguenze e verifica

- Database/schema separato per tenant non adottato: lo stack usa PostgreSQL condiviso e richiede elaborazioni globali controllate. Questo comporta disciplina su ogni ingresso, non solo sui controller.
- Esclusi team derivato sempre da `auth()`, fallback al primo team e assenza di filtro in console: falliscono con API, worker persistenti e accessi anonimi.
- Da M1: matrice con utenti di due team, ID diretti, membership revocata, contesto assente, binding, scritture e relazioni incrociate; estensione progressiva a job/CLI, ricerca, cache, broadcast e API. M8 verifica campi pubblicabili e rimozione dalla pubblicazione. Test automatici positivi e negativi restano obbligatori nella milestone che introduce il comportamento.

## ADR-004 — Autorizzazioni e limiti di piano centralizzati

### Contesto e decisione

I ruoli rispondono a chi può agire; i piani a quali risorse il team ha diritto. Manteniamo questi controlli distinti e componibili. Le policy verificano membership, ruolo owner/admin/member/viewer e proprietà della risorsa; le API aggiungono le abilità Sanctum. La matrice dettagliata dei permessi viene implementata/testata in M1, senza inventare qui permessi extra per ogni ruolo.

Un servizio coeso dei limiti legge una sola definizione dei piani e il loro stato effettivo; action, policy e middleware lo riusano. Limiti e motivi di rifiuto arrivano alla UI come dati, senza ricopiare valori in React. I job e le CLI richiamano le stesse regole quando creano/configurano/eseguono risorse. La UI può prevenire l'azione, ma il backend deve rifiutarla anche quando viene chiamato direttamente.

M0.08 fissava soltanto **Free: massimo cinque monitor**. ADR-010 (M0.09) completa ora matrice Free/Pro/Business, trial, intervalli e retention. Non introduciamo valori impliciti illimitati per configurazioni sconosciute: la definizione va validata e l'operazione non può procedere con un piano non risolto. Le regole di riconciliazione non devono impedire al sistema di ridurre un eccesso o registrare un risultato/consegna già avviati; i punti di applicazione vanno definiti per ciascun caso d'uso.

La creazione del monitor deve acquisire un lock sulla stessa riga Team usata dalle altre mutazioni della quota, ricontare e inserire nella stessa transazione. Un conteggio prima della transazione non basta contro due richieste simultanee. Le modifiche del piano e la riconciliazione devono coordinarsi con lo stesso confine, così un downgrade concorrente non autorizza nuove risorse usando uno stato vecchio.

In M9 un webhook Stripe verificato e deduplicato aggiorna lo stato dell'abbonamento Team; la riconciliazione sospende i monitor eccedenti in modo idempotente e deterministico, senza eliminare lo storico. Registriamo separatamente la sospensione dovuta al piano e la pausa volontaria per non riattivare una risorsa fermata dall'utente. Il criterio di selezione dei monitor, la semantica della scadenza/trial e la ripresa esplicita sono definiti in ADR-010 (M0.09), prima dell'implementazione.

Gli eventi asincroni richiedono rivalutazione dello stato applicabile al momento dell'esecuzione. Un vecchio job non può usare lo snapshot di un piano precedente per aggirare una sospensione; un webhook duplicato non duplica effetti. Il codice Cashier resta un adapter: ruoli e regole dei monitor non dipendono dai nomi degli eventi Stripe. Billing appartiene a Team, coerentemente con la preparazione M0.04.

### Alternative, conseguenze e verifica

- Esclusi limiti solo nel form o solo nel middleware HTTP: sarebbero aggirabili da API, action, job o CLI. Esclusi numeri duplicati in frontend e backend.
- Escluso affidarsi soltanto al conteggio o al lock Redis per garantire la quota persistita: il lock transazionale sul Team coordina le scritture PostgreSQL pertinenti.
- M2 verifica cinque monitor accettati e sesto rifiutato, ingressi diretti e richieste concorrenti reali su PostgreSQL; M9 aggiunge downgrade/scadenza concorrenti, webhook duplicati e pause volontarie; M10 verifica equivalenza API e abilità insufficienti. Il dettaglio dei piani diventa una fixture esplicita dopo M0.09.

## ADR-005 — Documentazione, decisioni e verifica evolutiva

### Contesto e decisione

Applichiamo la documentazione capillare di AGENTS.md a ogni nuovo file e modifica. Commenti di codice, docblock, PHPDoc e TSDoc/JSDoc sono in inglese; i documenti di pianificazione e questi record possono restare in italiano. Il requisito copre anche test, migration, factory, seeder, configurazioni e script. Si documentano le dichiarazioni e i relativi contratti; per le variabili locali bastano nomi e tipi chiari, con commenti ordinari soltanto quando utili.

Documentiamo scopo del file, responsabilità, significato dei dati, unità, nullabilità, forme degli array/DTO, invarianti, effetti collaterali ed errori pertinenti. PHPDoc e TSDoc/JSDoc con tag sono collocati prima delle dichiarazioni, mai nei corpi dei metodi o tra gli argomenti di una chiamata. Le annotazioni `@var` locali sono escluse; i callback brevi usano firme esplicite e i passaggi complessi metodi dedicati. Righe vuote separano i passaggi logici; array e query lunghe vengono espansi. Tipi e nomi restano espliciti: i commenti spiegano il significato, senza sostituire validazione o tipizzazione. Le modifiche aggiornano anche i commenti interessati e non introducono segreti o dati reali negli esempi.

JSON e lockfile restano senza commenti. Campi dei manifest e configurazioni JSON hanno un riferimento Markdown associato e collegato; i file generati sono documentati al livello del formato/proprietario, senza aggiungere annotazioni ai record di terze parti. Per manifest e lockfile il riferimento attuale è DEPENDENCIES, per TypeScript anche BOOTSTRAP/QUALITY/TESTING.

Ogni nuova decisione architetturale riporta ID stabile, data/task, stato, contesto, scelta, alternative, conseguenze, milestone di implementazione e verifiche. Un cambiamento sostanziale aggiunge un record che sostituisce esplicitamente quello precedente, conservandone il motivo storico. `docs/TASKS.md` registra adozione, deviazioni e risultati effettivi; una decisione adottata non completa automaticamente il task che deve implementarla. Riduzioni di scope richiedono decisione esplicita dell'utente, senza rimuovere M3/M4/M5.

PHP-CS-Fixer è l'unico formatter PHP, Larastan resta a livello 8; TypeScript rigoroso, lint/format frontend e test pertinenti seguono i comandi già documentati in [QUALITY.md](QUALITY.md), [TESTING.md](TESTING.md) e [CI.md](CI.md). Nessuna baseline di analisi o disattivazione di controlli per aggirare un errore introdotto.

### Alternative, conseguenze e verifica

- Esclusi documenti che affermano funzionalità non implementate, commenti generici e cambi di specifica non registrati. Il costo di aggiornare la documentazione è parte del task.
- In ogni revisione controlliamo copertura e lingua dei commenti, coerenza di tipi/unità e collegamenti. I formatter e Larastan verificano i rispettivi aspetti tecnici, non certificano da soli completezza o correttezza della documentazione: questa resta una verifica esplicita di revisione.
- Per M0.08, solo Markdown: controllare automaticamente destinazioni locali dei link e struttura/copertura dei cinque record; non introdurre test applicativi privi di un comportamento nuovo. Le suite applicative non provano decisioni ancora da implementare.

## ADR-006 — Scheduler e consegna recuperabile

Data/task: 2026-10-19, M0.09. Stato: adottato; implementazione M2–M3, osservabilità M11. Completa ADR-001 e ADR-002 senza introdurre consegna exactly-once.

### Contesto e decisione

La pianificazione ogni minuto della specifica non rende effettivi gli intervalli Business di 30 secondi. Il framework installato espone `everySecond()` e Compose avvia già `schedule:work`: in M3 il dispatch sarà pianificato ogni secondo, senza modificare lo scheduler in questa sessione. Lock distribuiti e coordinamento dello scheduler riducono il lavoro duplicato; la correttezza persistita resta responsabilità di PostgreSQL.

Gli slot sono ancorati al tempo UTC con fase deterministica derivata dall'identità del monitor modulo intervallo. L'avanzamento sceglie il primo slot strettamente futuro rispetto al riferimento della prenotazione, senza aggiungere l'intervallo all'ora effettiva del worker. Si prenota soltanto lo slot dovuto più recente: gli slot saltati durante un fermo non generano raffiche, campioni sintetici o uptime inventato. Cambio intervallo e ripresa ricalcolano lo slot futuro.

La selezione avviene a blocchi per ID; nella transazione si ricontrollano scadenza, stato e piano sotto lock del monitor. La stessa transazione avanza `next_check_at` e registra il lavoro persistente da pubblicare per ciascuna regione. Identità stabile e vincolo univoco: team, monitor, slot UTC e regione. Un dispatcher concorrente non può prenotare due volte lo stesso lavoro. La prenotazione viene annullata integralmente al rollback.

Un publisher recuperabile legge i lavori confermati e invia i job su `checks:eu` o `checks:us`. Il crash tra commit e invio lascia un lavoro ripubblicabile; il crash dopo invio ma prima della conferma può duplicare la consegna. Anche i lavori pubblicati senza avvio sono riconciliati: la sola conferma di invio non prova l'esecuzione. Il job acquisisce atomicamente il lavoro prima dell'I/O; una consegna duplicata non avvia una seconda richiesta. Un lavoro mai iniziato che ha superato il proprio slot utile viene chiuso come osservazione mancata, anziché eseguire un check storico sul bersaglio corrente.

Per i lavori iniziati servono un termine massimo persistito, coerente con timeout e margini del worker, e un recuperatore che terminalizzi quelli abbandonati. Nessuna ripetizione del traffico al bersaglio dopo un avvio registrato. L'eventuale crash tra acquisizione e primo byte può quindi perdere un'osservazione: è un limite dichiarato, preferito a una falsa garanzia exactly-once. Risultato ordinario, `failed()` e recuperatore competono sulla stessa transizione terminale idempotente. Il fencing dell'acquisizione impedisce a un worker tardivo di sovrascrivere un lavoro già terminale.

### Alternative, conseguenze e verifica

- Esclusi solo `afterCommit`, solo lock Redis o invio Redis dentro la transazione: non forniscono atomicità fra database e broker. Il registro persistente comporta publisher, riconciliazione e pulizia propri.
- M3 definisce e verifica timeout operativi, lease e margini rispetto ai limiti reali dei trasporti; non sono promesse di puntualità sotto sovraccarico. I ritardi e i periodi non osservati restano visibili.
- M2–M3: jitter ripetibile, intervallo 30 secondi, nessuna deriva, cambio intervallo, pause, chunk e tenant; prenotazioni concorrenti su PostgreSQL; crash prima/dopo commit, prima/dopo invio, messaggi persi o duplicati, avvio perso e completamento tardivo. Verificare un solo effetto persistito, senza affermare che la rete sia exactly-once.

## ADR-007 — Campioni, retry e conferma regionale

Data/task: 2026-10-19, M0.09. Stato: adottato; implementazione M3–M4. Completa ADR-002 e distingue fallimenti del bersaglio da assenza di osservazione.

### Contesto e decisione

`tries = 1` resta obbligatorio. Nessun retry automatico del client HTTP e nessun retry di dominio iniziale: il prossimo slot è una nuova osservazione. Questo chiarisce il riferimento a `retry()` della specifica senza nascondere fallimenti. I retry di consegna delle notifiche restano M5; ripubblicare un lavoro mai acquisito segue ADR-006 e non ripete un check iniziato.

La soglia predefinita è tre fallimenti consecutivi per regione, configurabile come intero positivo. Un successo azzera la sequenza della regione. Uno slot senza osservazione valida o un errore infrastrutturale interrompe la sequenza: non dimostra un fallimento consecutivo del bersaglio. Timeout del bersaglio e errori TLS/DNS effettivamente osservati sono invece fallimenti di check; errore del worker, coda o recuperatore sono errori infrastrutturali distinti e visibili.

Con una regione, il raggiungimento della soglia apre l'incidente; un successivo successo lo risolve. Con due regioni, entrambe devono raggiungere la soglia e fallire nello stesso slot per aprirlo; un successo di entrambe nello stesso slot lo risolve. Non si aggiungono sonde fuori programma né si aspetta sincronicamente una regione nel worker dell'altra. Le regioni sono simulate e non dimostrano disponibilità geografica reale.

Campioni discordanti o incompleti non aprono né risolvono da soli un incidente. I contatori avanzano soltanto in ordine di slot per regione; un risultato precedente al watermark già elaborato resta storico e non riscrive transizioni, soglie o notifiche. La valutazione congiunta avviene quando lo slot dispone degli esiti necessari, con transazione e vincoli contro incidenti duplicati. Un risultato duplicato non incrementa un contatore. Cambiare le regioni azzera le sequenze; l'eventuale incidente aperto richiede un nuovo slot completo secondo la configurazione corrente per risolversi.

### Alternative, conseguenze e verifica

- Esclusi retry per ottenere un successo, conferme fra slot diversi e recupero dedotto da una sola regione quando ne sono configurate due. La conferma stretta può ritardare la risoluzione se una regione manca: lo stato incompleto deve essere visibile.
- M3–M4: soglia tre senza incidente al primo fallimento, successo intermedio, sequenza interrotta da dato mancante, una/due regioni, discordanza, slot incompleto, risultati fuori ordine/duplicati e concorrenza di apertura/chiusura.
- M4 conserva le regole di manutenzione e pubblicazione; soppressioni e quiet hours delle notifiche non alterano retroattivamente i campioni.

## ADR-008 — Retention, rollup e percentili esatti

Data/task: 2026-10-19, M0.09. Stato: adottato; implementazione M3 e M7–M9. Adeguamento esplicito della specifica approvato dall'utente: 30 giorni logici, eliminazione fisica per partizioni mensili interamente scadute.

### Contesto e decisione

Il timestamp dei risultati e i confini delle partizioni sono UTC. I risultati grezzi sono consultabili soltanto nella finestra mobile degli ultimi 30 giorni, per tutti i piani, anche se fisicamente ancora presenti. Query interne ed esportazioni rispettano lo stesso limite; la manutenzione dei rollup usa un accesso tecnico ristretto ai dati necessari, senza renderli consultabili ai tenant.

Una partizione mensile è eliminabile quando il suo estremo finale è precedente o uguale al cutoff dei 30 giorni e i rollup necessari sono verificati. Non si eseguono cancellazioni riga per riga sui grezzi. La permanenza fisica può arrivare a circa 61 giorni, più ritardo della pulizia; non è una promessa di cancellazione fisica entro 30 giorni. Un errore di aggregazione blocca il drop ed espone il ritardo operativo. I rollup vengono prodotti incrementalmente ogni notte anche per i dati recenti, non soltanto dopo la scadenza dei grezzi.

Rollup orari e giornalieri restano per 90/365/730 giorni secondo ADR-010, insieme alle statistiche ausiliarie. Al downgrade il nuovo cutoff logico si applica subito e la pulizia fisica alla successiva esecuzione; un upgrade non recupera dati già eliminati. Lo storico pubblico resta al massimo di 90 giorni, anche sui piani con storico privato più lungo.

Le latenze misurabili sono normalizzate al millisecondo intero più vicino, con mezzi millisecondi arrotondati verso l'alto. Valori non misurabili sono null e non entrano nel campione; timeout senza misura valida non diventano zero. Conservare per ogni bucket conteggio delle latenze, somma e frequenze per valore intero. Il percentile p è il valore alla posizione `ceil(p * n)` nell'insieme ordinato, con p pari a 0,50 o 0,95; nessun campione produce null. I giornalieri si ottengono unendo le frequenze orarie, mai mediando percentili; la media usa somma/conteggio, non la media delle medie.

Questa rappresentazione è esatta rispetto ai millisecondi normalizzati, ma può crescere con il numero di valori distinti: non è uno sketch a memoria costante. M7 deve misurarne il costo, senza sostituirla tacitamente con un'approssimazione. Ricostruzione dei bucket e verifica prima del drop sono idempotenti. Le durate SLA e i dati di copertura necessari dopo la scadenza dei grezzi devono essere conservati insieme ai rollup, senza dedurli dai soli conteggi di check.

### Alternative, conseguenze e verifica

- Escluse cancellazioni parziali dei grezzi per rispettare 30 giorni fisici; la distinzione logico/fisico risolve il conflitto con le partizioni mensili. La specifica originale rimane conservata e la deviazione è registrata anche in TASKS.
- Escluse medie dei p95 orari, conservazione dei soli percentili e latenza nulla convertita in zero. Nessuna libreria aggiunta in M0.09.
- M3/M7: mesi da 28/29/30/31 giorni, anno bisestile, cutoff esatto, partizioni future, rollup incompleto che impedisce il drop, riesecuzione, bucket parziali e isolamento tenant. Percentili: insieme vuoto, singolo valore, ripetizioni, null, cardinalità diseguali fra ore e aggregazione giornaliera esatta.
- M8 verifica 90 giorni pubblici; M9 verifica cutoff dopo cambio piano, cancellazione delle frequenze ausiliarie e assenza di recupero di dati eliminati.

## ADR-009 — SLA temporale e copertura

Data/task: 2026-10-19, M0.09. Stato: adottato; implementazione M4 e M6–M8. Completa la formula temporale richiesta dalla specifica.

### Contesto e decisione

Le finestre sono intervalli semiaperti `[inizio, fine)`. Il denominatore eleggibile è la durata della finestra ritagliata sulla vita del monitor, meno l'unione di pause e manutenzioni; finestre sovrapposte non si sottraggono due volte. Servono intervalli storici di pausa/sospensione e manutenzione, non soltanto flag correnti. La sospensione dovuta al piano è una pausa tracciata, distinta da quella volontaria.

Un campione valido determina up/down dal proprio istante di osservazione fino alla scadenza del successivo slot previsto, oppure fino al campione valido successivo se arriva prima. Una volta superata la scadenza senza nuovo dato valido, lo stato è sconosciuto; un risultato tardivo non riempie retroattivamente il buco prima della sua osservazione. Il periodo prima della prima osservazione è sconosciuto. Configurazioni e intervalli applicabili storicamente devono essere ricostruibili per non usare l'intervallo corrente sul passato.

Per un monitor a due regioni, up richiede osservazioni valide e up per entrambe; down richiede entrambe valide e down; discordanza o copertura regionale incompleta sono sconosciute. Per una regione si usa il suo esito valido. Soglia degli incidenti e ritardi di conferma non trasformano i primi fallimenti validi in uptime.

Siano U la durata up, D la durata down e X la durata sconosciuta, dopo le esclusioni. Esporre sempre le tre durate e la copertura `(U + D) / (U + D + X)`. L'uptime osservato è `U / (U + D)`; lo SLA della finestra è determinabile soltanto se X è zero e il denominatore eleggibile è positivo. Denominatore nullo, assenza completa di dati o copertura incompleta producono SLA null, mai 100% implicito. Con nessun dato osservato anche l'uptime osservato è null; con denominatore eleggibile nullo la copertura è null.

Bucket tecnici in UTC; timezone IANA del team per richieste di calendario e report mensili, convertendo poi i confini in UTC e rispettando DST. Calcoli e rollup conservano durate sufficienti a ricomporre la finestra, incluse informazioni sui bordi: contare i check non sostituisce il tempo e un rollup giornaliero non autorizza a inventare la distribuzione dentro il giorno. Dopo scadenza dei grezzi, richieste con bordi non ricostruibili dai dati conservati devono dichiarare il dato non disponibile, senza interpolazione nascosta.

### Alternative, conseguenze e verifica

- Esclusi `check ok / check totali`, dati mancanti trattati come successi e ultimo stato propagato senza scadenza. La scelta utente separa gli sconosciuti anziché contarli automaticamente come downtime.
- M4 registra pause/manutenzioni; M7 verifica ritaglio, intersezioni, finestre adiacenti/sovrapposte, campione al confine, cambio intervallo, risultato tardivo, dati mancanti e denominatore nullo. M6/M8 mostrano uptime osservato, copertura e SLA assente senza mascherarli con uno zero.
- Testare due regioni discordanti, timezone, giorni da 23/25 ore, mese/anno e consistenza fra dati grezzi e rollup su finestre ricostruibili.

## ADR-010 — Piani, trial e riconciliazione

Data/task: 2026-10-19, M0.09. Stato: adottato; implementazione M1–M2 e M7–M10. Completa ADR-004 con valori e comportamento approvati dall'utente. Prezzi e identificatori Stripe non sono definiti da questo record.

### Contesto e decisione

| Piano | Monitor | Intervallo minimo | Status page | Retention rollup |
|---|---:|---:|---:|---:|
| Free | 5 | 300 secondi | 1 | 90 giorni |
| Pro | 50 | 60 secondi | 3 | 365 giorni |
| Business | 200 | 30 secondi | 10 | 730 giorni |

I grezzi hanno 30 giorni logici su tutti i piani. Il trial concede Pro per 14 giorni esatti dalla creazione del team, senza carta, una sola volta per team; conservare l'avvenuto utilizzo. Un abbonamento valido prevale sul trial; in assenza di diritto pagato o trial valido il piano effettivo è Free. Alla scadenza esatta il trial non concede più Pro. Cancellazione a fine periodo conserva il diritto fino alla scadenza pagata; nessuna grazia applicativa aggiuntiva oltre il periodo cui il team ha diritto. Eventi Stripe fuori ordine richiedono riconciliazione dello stato autorevole, non regressione allo snapshot di un vecchio webhook.

Un downgrade, fine trial o perdita del diritto avvia riconciliazione idempotente sotto lo stesso lock Team delle mutazioni di quota. L'ordine è crescente per `created_at`, poi ID: i monitor e le pagine più vecchi restano ammessi, gli eccedenti sono sospesi/oscurati senza cancellazione dello storico. Anche risorse già in pausa o private occupano quota e posizione; così una pausa non libera una quota di creazione aggiuntiva. Le risorse conservate oltre quota impediscono nuove creazioni finché il team non riduce il totale o aumenta il piano.

Gli intervalli inferiori al minimo del nuovo piano vengono portati al minimo e lo slot futuro viene ricalcolato senza raffiche. L'adeguamento è persistito e visibile, non un rallentamento nascosto nel worker. Le pagine eccedenti diventano indisponibili pubblicamente, con invalidazione della cache e senza revoca distruttiva di configurazioni o subscriber. Il team può continuare a gestire/eliminare le proprie risorse sospese; non può usarle per aggirare il limite.

Un upgrade aumenta l'ammissibilità ma non riattiva, ripubblica o accelera automaticamente le risorse. Ripresa e ripubblicazione richiedono azioni esplicite e autorizzate, con nuova verifica della quota e delle altre restrizioni. La ripresa del piano non rimuove una pausa volontaria; la rimozione della pausa volontaria non rimuove una sospensione di piano. Le impostazioni di frequenza possono essere nuovamente modificate entro il piano corrente.

UI, API, CLI e job condividono la definizione centralizzata. Un job vecchio rivaluta ammissibilità e configurazione prima dell'I/O; se non è più ammesso termina senza traffico e senza inventare un guasto. Un risultato di un check già avviato può essere registrato idempotentemente. La pulizia applica ADR-008; l'upgrade non ripristina lo storico eliminato. Nessuna riduzione di dominio personalizzato, PDF o portale clienti è autorizzata da questi limiti.

### Alternative, conseguenze e verifica

- Escluse risorse illimitate implicite, cancellazione automatica delle eccedenze e riattivazione automatica dopo upgrade. La scelta deterministica privilegia stabilità e rende spiegabile la riconciliazione.
- M1 prepara gli attributi Team necessari quando introduce il modello; M2 applica monitor/frequenze e quota concorrente; M7 retention; M8 pagine; M9 trial, Stripe e riconciliazione completa; M10 equivalenza API. Nessuna tabella billing anticipata in M0.09.
- Testare 5/6 monitor Free, due creazioni concorrenti, trial al confine di 14 giorni, fine periodo pagato, webhook duplicati/fuori ordine, downgrade concorrente a creazione/dispatch, date di creazione uguali, risorse in pausa, eccedenza pagine e cache, upgrade senza ripresa implicita e protezione cross-tenant.

## ADR-011 — Operatore di piattaforma e override di entitlement

Data/task: 2026-10-23, MA. Stato: adottato; implementazione MA.01–MA.04. Fuori dalla specifica, per scelta di scope dell'utente.

### Contesto e decisione

Servono prove senza vincoli di piano e piani concessi senza sottoscrizione.
- **Ruolo.** L'operatore di piattaforma è un flag su `users`, separato dai ruoli dei team e modificabile solo da CLI. Il gate rilegge il flag a ogni richiesta e in produzione richiede la 2FA.
- **Horizon.** Usa lo stesso gate.
- **Override.** Un override per team (illimitato o piano omaggio) entra nella precedenza di `TeamEntitlement` prima del piano salvato e del trial; un omaggio non abbassa mai un piano pagato.
- **Profilo illimitato.** Il caso illimitato usa il profilo finito `internal` di `config/plans.php`, così l'invariante di ADR-010 («nessun valore illimitato») resta valida.
- **Concorrenza e audit.** Le modifiche prendono il lock Team di ADR-004 e scrivono un evento di audit append-only nella stessa transazione.

### Conseguenze

- `MonitorLimits` e tutti i futuri consumatori del piano effettivo rispettano gli override senza logica propria.
- M9 deve preservare la precedenza e riconciliare la scadenza o la revoca di un override.
- Il pannello legge dati di tutti i team: è l'unica eccezione a TeamScope, limitata ai conteggi e protetta dal gate. La risposta 404 nasconde il pannello ai non operatori.
- Escluse l'email dell'operatore in variabile d'ambiente (attributo mutabile, nessuna traccia, redeploy per revocare) e la promozione via HTTP.

Dettagli in [PLATFORM-ADMIN.md](PLATFORM-ADMIN.md).

## Stato dopo M0.09

ADR-006–ADR-010 chiudono le decisioni fondanti residue e completano ADR-001–ADR-005. Tutte sono indirizzi adottati per le milestone indicate, non funzionalità disponibili. M0.09 non aggiunge API pubbliche, contratti PHP/TypeScript eseguibili, tabelle, dipendenze o cambi allo scheduler corrente. Le future interfacce rappresenteranno identità di esecuzione, classificazione infrastrutturale, copertura SLA e motivi distinti di sospensione nei task proprietari.

La verifica finale del 2026-10-19 ha completato i criteri di uscita di M0: bootstrap e smoke test da checkout pulito superati, con gli esiti CI PHP 8.4/8.5 precedentemente confermati dall’utente. Evidenze e limiti sono nel registro di [TASKS.md](TASKS.md); questi record non certificano le funzionalità future. Il prossimo task pronto è M1.01.

## M1.01 — Chiarimento proprietà e implementazione iniziale

Data: 2026-10-20. Decisione esplicita utente: più membership owner sono consentite; `teams.owner_id` identifica il titolare principale, che la creazione transazionale registra anche come owner. Non viene introdotto un vincolo di unicità sul ruolo owner. Trasferimento e protezione dell'ultimo owner restano M1.03.

M1.01 implementa soltanto la fondazione dati di ADR-003/004/010: identità Team/Membership, ruoli/piani tipizzati, timezone, preferenza team corrente risolta attraverso membership attuale e trial iniziale con inizio/fine persistiti. La creazione usa action coesa e lock transazionale sull'utente per non sovrascrivere la prima selezione sotto concorrenza; nessun repository o contratto senza varianti reali. L'invariante del titolare owner è mantenuta dalla creazione e dalle factory, non da un trigger che anticipi la gestione membri.

Dettagli e limiti in [IDENTITY.md](IDENTITY.md). TeamScope, tenant context, policy e risoluzione del diritto effettivo non sono implementati da questo task; gli ADR precedenti restano adottati per le rispettive milestone. Nessuna deviazione dallo stack o riduzione di scope.

## M1.02 — Autenticazione web e protezione dell'identità

Data: 2026-10-20. Decisioni confermate dall'utente: registrazione con nome del primo team, slug generato dal server, verifica email obbligatoria, 2FA facoltativa, UI/email in inglese. Fortify fornisce pipeline/controller e primitive mantenute; le rotte sono dichiarate dall'applicazione e i form restano Inertia 2/React, senza cambiare starter o stack.

L'action RegisterUser compone CreateTeam nella stessa transazione e pubblica Registered dopo commit. L'identità email è normalizzata e protetta da indice PostgreSQL case-insensitive; collisioni pregresse fermano la migration senza fusioni. La registrazione non accetta owner, ruolo, piano o trial dal client.

Conferma password recente, challenge pendente a tempo e fingerprint delle credenziali proteggono le transizioni. Consumo/rigenerazione recovery e reset password si coordinano sulla riga User; uno scope tenant non è appropriato per queste operazioni di identità. Il provider TOTP delega la crittografia alla libreria e adatta soltanto clock/replay cache, isolando i contatori per segreto. Props condivise e pagina account espongono proiezioni minime; dettagli 2FA restano su JSON no-store e stato UI non ricordato. Dettagli in [AUTHENTICATION.md](AUTHENTICATION.md).

Questa attuazione non sostituisce ADR-003/004: tenant context, policy e gestione membri restano nei task successivi. Adeguamento dipendenze registrato: Fortify ^1.41 e correzione mirata dell'advisory HttpFoundation con versione compatibile 7.4.21; nessuna riduzione di scope.

## M1.03 — Inviti e transizioni di membership

Data: 2026-10-21. Decisioni utente: inviti validi 7 giorni, email verificata corrispondente e accettazione esplicita; reinvio invalida il token precedente. Owner gestiscono tutti i ruoli, admin soltanto member/viewer; uscita volontaria per ogni membro salvo vincoli owner. Il titolare trasferisce esplicitamente a un owner esistente con password recente e conserva il ruolo owner. Registrazione da invito senza team personale.

Le action di identità acquisiscono il lock Team, rivalutano membership/ruoli e poi, quando modificano la preferenza, il lock User. Token casuali restano soltanto nella notifica sincrona dopo commit; database/sessione conservano il digest e la UI nessuna credenziale. Una riga unica team/email rappresenta il ciclo di vita riemesso; replay non promuove né ripristina membri rimossi. Le scritture composite sul pivot usano chiavi esplicite. TeamAccess riusa la gerarchia senza introdurre il sistema generale di policy/context/TeamScope di M1.04. Dettagli, permessi, rotte e limiti in [TEAMS.md](TEAMS.md).

### Chiarimento documentazione — 2026-10-21

L'utente ha respinto la documentazione di ogni variabile locale applicata in M1.03 perché interrompeva la lettura. Questa decisione sostituisce quel requisito di AGENTS/ADR-005: documentazione prima delle dichiarazioni, soli commenti ordinari necessari nei corpi e nessuna annotazione locale per forzare i tipi. La revisione M1.03 mantiene firme esplicite, introduce un controllo reale dell'identità autenticata nel controller comune e separa le proiezioni complesse in metodi documentati. Contratti, permessi e ordine dei lock restano quelli approvati.

## M1.04 implementation follow-up

ADR-003/004 now have concrete request-scoped TenantContext, fail-closed TeamScope/BelongsToTeam on TeamInvitation, authorized HTTP middleware ordered before binding, and team/invitation policies sharing TeamAccess. User, Team and initial membership lookup remain outside the scope. The sole capability resolver performs a restricted scope-free lookup; acceptance derives context from the proved invitation and revalidates under lock. Existing transactional ownership and role rules remain unchanged.

[TENANCY.md](TENANCY.md) documents explicit nested lifetimes, model reload/restoration safeguards, scalar-only tenant jobs and worker/CLI tests. M1.04 introduces no speculative product resource, schema change or generic bypass. Raw/bulk persistence remains an explicit trusted boundary. API, public status, search/cache and broadcast enforcement are added with their owning features; M1.05 supplies the complete parametrized matrix of available entrances.

## M1.05 verification follow-up

M1.05 verifies ADR-003/004 with an independent route catalogue and actor/target role expectations, cross-team nested identifiers, stale membership/preferences, bounded capabilities and assertions of both allowed effects and unchanged persistence on denial. [AUTHORIZATION-MATRIX.md](AUTHORIZATION-MATRIX.md) describes discovery and extension rules. Existing scope, worker and PostgreSQL concurrency suites remain enabled. No domain permission, scope bypass or new transport is introduced; M1 completion covers implemented paths and subsequent milestones extend the matrix with their resources.

## M2.01 monitor foundation

[MONITORS.md](MONITORS.md) documents the tenant Monitor model, five readonly configuration variants, composition of HTTP settings for keyword monitors, dedicated-column mapper and typed canonical simulated regions. The specification's column layout is extended for TLS/DNS/heartbeat; URL/header/body use encrypted text columns, while DNS expectations and regions use JSONB. Configuration replacement clears irrelevant fields without implicit persistence.

The shared tenant trait protects Monitor and the Team inverse relation. Initial status is unknown, scheduling/observation timestamps are null, and voluntary pause is separate from plan suspension. UTC instants are normalized before SQL formatting. Plan limits, regional incident state, scheduling transitions, network validation, SSRF and checker I/O remain in their owning tasks; no resource controller or policy is anticipated. PostgreSQL constraints and test-only factories verify this foundation without provisioning implicit teams or contacting targets.

## M2.02 monitor input boundary

[MONITOR-INPUT.md](MONITOR-INPUT.md) defines the network-free validator, configurable operational bounds and Form Request adapters. Syntax parsing, limit configuration and HTTP adaptation have separate responsibilities; existing variant DTOs remain the typed output. Validation returns both a complete candidate and an explicit normalized patch so future transactional actions can revalidate that patch against a locked fresh snapshot, preserving concurrent edits and omitted secrets. No speculative mutation service or transport is introduced.

Authorization runs before configuration decryption; absent tenant context or MonitorPolicy denies access. Test-only grants/routes cover the adapters without exposing production CRUD. Global string transformations are bypassed only for the defined numeric monitor mutation paths, preserving meaningful bytes for the dedicated normalizer. Validation errors never flash original monitor input or echo secret values. This boundary does not replace M2.03 outbound security or M2.04 policy/plan enforcement.

## M2.03 outbound security boundary

[OUTBOUND-SECURITY.md](OUTBOUND-SECURITY.md) implements the ADR-002 SSRF boundary as small services: a byte-level address policy with an explicit, empty-by-default CIDR exception list; a replaceable host resolver; a guard that authorizes URLs, TLS endpoints and resolvers only when every resolved address is allowed; Guzzle options pinning the connection to those addresses; and a redirect policy that re-authorizes each hop, refuses downgrades and drops configured headers across origins. Form validation applies only the network-free subset. Secret redaction is centralized in one redactor used by a Monolog processor tapped into every concrete channel and by the safe configuration projection. Transports, retry semantics and checker results remain M3/M5.

## M2.04 monitor mutations and plan limits

[MONITOR-CRUD.md](MONITOR-CRUD.md) applies ADR-004/ADR-010 to monitors. `config/plans.php` is the single plan matrix read by `PlanLimits`; `TeamEntitlement` resolves the effective plan (stored Pro/Business as paid placeholder until M9, otherwise Pro during the exclusive 14-day trial, else Free); `MonitorLimits` checks quota, cadence and deterministic quota position. One cohesive action, `ManageMonitors`, locks Team then Monitor, re-runs `MonitorPolicy` and re-validates the explicit patch under lock, as the identity actions do. Viewers are read-only; deletion is permanent; voluntary pause and plan suspension stay independent. Scheduling slots are left null for the M3 dispatcher; no events are published before M6.

## M2.05 monitor pages

[MONITOR-UI.md](MONITOR-UI.md) separates read pages (`MonitorPageController`) from mutations (`MonitorController`). Projections live in one presenter that reads configuration only through `MonitorConfigurationProjection`. Tenant-filtered search is a small service over Scout with an explicit team predicate and literal LIKE escaping (ADR-003). Quota and permission props are ADR-004 hints only; the lock-aware action remains the authority. Invalid list filters are reported rather than redirected, to avoid a GET redirect loop.

## M2.06 integrated monitor coverage

M2.06 adds tests and test support only; no production code changes. It verifies ADR-004 on the real transport: a real `artisan serve` process with several workers receives the Inertia submissions, and the sixth Free creation is shown to wait on the same Team row lock and then be refused. A shared JSON contract keeps the React form payloads and server validation in step. Details are in [MONITOR-UI.md](MONITOR-UI.md#integrated-coverage-m206) and [TESTING.md](TESTING.md).
