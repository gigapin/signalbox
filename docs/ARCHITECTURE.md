# Signalbox — Decisioni architetturali

Data: 2026-10-16. Task: M0.08. Fonte dei requisiti: [specifica](03-Progetto-B-Signalbox-Laravel.md), [AGENTS.md](../AGENTS.md) e [piano operativo](TASKS.md). Stack e adeguamenti verificati restano in [DEPENDENCIES.md](DEPENDENCIES.md); configurazioni effettive in [BOOTSTRAP.md](BOOTSTRAP.md) e [SERVICES.md](SERVICES.md).

Questi record stabiliscono come implementare le milestone successive. Stato di tutti i record: **adottato come indirizzo architetturale; implementazione nelle milestone indicate**. Non attestano funzionalità già disponibili. A M0.08 esistono il bootstrap Laravel/Inertia, User, configurazioni dei pacchetti e infrastruttura test/CI; Team, monitor, checker, tenant context e policy dei piani devono ancora essere implementati.

## Indice dei record

| ID | Decisione | Implementazione |
|---|---|---|
| ADR-001 | Monolite modulare e responsabilità del dominio | M1–M12, progressivamente |
| ADR-002 | Strategy dei checker e adapter di trasporto | M2–M3, notifiche M5 |
| ADR-003 | Tenant context esplicito e accessi pubblici separati | M1, esteso in ogni milestone |
| ADR-004 | Autorizzazioni e limiti di piano centralizzati | M1–M2, billing M9, API M10 |
| ADR-005 | Documentazione, decisioni e verifica evolutiva | Ogni task |

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

Gli eventi che avviano effetti esterni vengono pubblicati dopo il commit della transizione; il consumatore è idempotente. Questa regola evita effetti su dati annullati, ma non risolve da sola il crash tra commit e invio: il protocollo affidabile di prenotazione/invio è una decisione obbligatoria di M0.09, da implementare e testare in M3. Nessuna promessa di consegna exactly-once.

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

Un timeout o errore del bersaglio diventa un risultato di fallimento; i check mantengono `tries = 1`. Eventuali retry di dominio richiedono semantica documentata in M0.09 e test propri; non usiamo retry automatici della coda o del client HTTP per occultare un campione fallito. Le notifiche hanno invece retry di consegna espliciti in M5.

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

Il solo valore quantitativo già stabilito è **Free: massimo cinque monitor**. Altri valori Free, Pro/Business, trial, intervalli e retention restano M0.09. Non introduciamo valori impliciti illimitati per configurazioni sconosciute: la definizione va validata e l'operazione non può procedere con un piano non risolto. Le regole di riconciliazione non devono impedire al sistema di ridurre un eccesso o registrare un risultato/consegna già avviati; i punti di applicazione vanno definiti per ciascun caso d'uso.

La creazione del monitor deve acquisire un lock sulla stessa riga Team usata dalle altre mutazioni della quota, ricontare e inserire nella stessa transazione. Un conteggio prima della transazione non basta contro due richieste simultanee. Le modifiche del piano e la riconciliazione devono coordinarsi con lo stesso confine, così un downgrade concorrente non autorizza nuove risorse usando uno stato vecchio.

In M9 un webhook Stripe verificato e deduplicato aggiorna lo stato dell'abbonamento Team; la riconciliazione sospende i monitor eccedenti in modo idempotente e deterministico, senza eliminare lo storico. Registriamo separatamente la sospensione dovuta al piano e la pausa volontaria per non riattivare una risorsa fermata dall'utente. Il criterio di selezione dei monitor, la semantica della scadenza/trial e la politica di ripresa sono da definire in M0.09 prima dell'implementazione; non presumiamo una riattivazione automatica.

Gli eventi asincroni richiedono rivalutazione dello stato applicabile al momento dell'esecuzione. Un vecchio job non può usare lo snapshot di un piano precedente per aggirare una sospensione; un webhook duplicato non duplica effetti. Il codice Cashier resta un adapter: ruoli e regole dei monitor non dipendono dai nomi degli eventi Stripe. Billing appartiene a Team, coerentemente con la preparazione M0.04.

### Alternative, conseguenze e verifica

- Esclusi limiti solo nel form o solo nel middleware HTTP: sarebbero aggirabili da API, action, job o CLI. Esclusi numeri duplicati in frontend e backend.
- Escluso affidarsi soltanto al conteggio o al lock Redis per garantire la quota persistita: il lock transazionale sul Team coordina le scritture PostgreSQL pertinenti.
- M2 verifica cinque monitor accettati e sesto rifiutato, ingressi diretti e richieste concorrenti reali su PostgreSQL; M9 aggiunge downgrade/scadenza concorrenti, webhook duplicati e pause volontarie; M10 verifica equivalenza API e abilità insufficienti. Il dettaglio dei piani diventa una fixture esplicita dopo M0.09.

## ADR-005 — Documentazione, decisioni e verifica evolutiva

### Contesto e decisione

Applichiamo la documentazione capillare di AGENTS.md a ogni nuovo file e modifica. Commenti di codice, docblock, PHPDoc e TSDoc/JSDoc sono in inglese; i documenti di pianificazione e questi record possono restare in italiano. Il requisito copre anche test, migration, factory, seeder, configurazioni e script, incluse proprietà, costanti, parametri, callback e variabili locali.

Documentiamo scopo del file, responsabilità, significato dei dati, unità, nullabilità, forme degli array/DTO, invarianti, effetti collaterali ed errori pertinenti. PHP usa descrizioni e tag appropriati `@param`, `@return`, `@throws`, `@var`; TypeScript usa TSDoc/JSDoc e commenti locali. Tipi e nomi restano espliciti: i commenti spiegano il significato, senza sostituire validazione o tipizzazione. Le modifiche aggiornano anche i commenti interessati e non introducono segreti o dati reali negli esempi.

JSON e lockfile restano senza commenti. Campi dei manifest e configurazioni JSON hanno un riferimento Markdown associato e collegato; i file generati sono documentati al livello del formato/proprietario, senza aggiungere annotazioni ai record di terze parti. Per manifest e lockfile il riferimento attuale è DEPENDENCIES, per TypeScript anche BOOTSTRAP/QUALITY/TESTING.

Ogni nuova decisione architetturale riporta ID stabile, data/task, stato, contesto, scelta, alternative, conseguenze, milestone di implementazione e verifiche. Un cambiamento sostanziale aggiunge un record che sostituisce esplicitamente quello precedente, conservandone il motivo storico. `docs/TASKS.md` registra adozione, deviazioni e risultati effettivi; una decisione adottata non completa automaticamente il task che deve implementarla. Riduzioni di scope richiedono decisione esplicita dell'utente, senza rimuovere M3/M4/M5.

PHP-CS-Fixer è l'unico formatter PHP, Larastan resta a livello 8; TypeScript rigoroso, lint/format frontend e test pertinenti seguono i comandi già documentati in [QUALITY.md](QUALITY.md), [TESTING.md](TESTING.md) e [CI.md](CI.md). Nessuna baseline di analisi o disattivazione di controlli per aggirare un errore introdotto.

### Alternative, conseguenze e verifica

- Esclusi documenti che affermano funzionalità non implementate, commenti generici e cambi di specifica non registrati. Il costo di aggiornare la documentazione è parte del task.
- In ogni revisione controlliamo copertura e lingua dei commenti, coerenza di tipi/unità e collegamenti. I formatter e Larastan verificano i rispettivi aspetti tecnici, non certificano da soli completezza o correttezza della documentazione: questa resta una verifica esplicita di revisione.
- Per M0.08, solo Markdown: controllare automaticamente destinazioni locali dei link e struttura/copertura dei cinque record; non introdurre test applicativi privi di un comportamento nuovo. Le suite applicative non provano decisioni ancora da implementare.

## Decisioni ancora da chiudere in M0.09

| Tema | Decisione richiesta prima dell'implementazione |
|---|---|
| Scheduler e affidabilità | Intervalli sotto il minuto, prenotazione/avanzamento di next_check_at, consegna dopo commit e recupero di lavoro perso o duplicato |
| Retry e conferma regionale | Semantica dei tentativi di dominio, una/due regioni e campioni discordanti o fuori ordine |
| Retention | Compatibilità fra 30 giorni grezzi, partizioni mensili e verifica dei rollup prima della rimozione |
| SLA e percentili | Intervalli parziali, pause/manutenzioni, dati mancanti, denominatore nullo e aggregabilità di p50/p95 |
| Piani e riconciliazione | Matrice Free/Pro/Business e trial, selezione deterministica delle eccedenze, scadenza e ripresa dopo variazione del piano |

M0.08 non aggiunge API pubbliche, contratti PHP/TypeScript eseguibili, nuove tabelle o dipendenze. M0 resta aperta fino a M0.09 e al soddisfacimento dei criteri di uscita del piano.
