# Dashboard utenti — brief e piano di realizzazione

Decisione dell’utente del 2026-11-09: dare priorità a una dashboard e a una navigazione che rendano fruibile l’applicazione, partendo dallo screenshot UptimeRobot allegato nella conversazione e adattandolo progressivamente. Il riferimento rimane nella conversazione: non viene copiato nel repository. Il piano operativo e gli stati sono nel [macro task UI](TASKS.md#ui--dashboard-utenti-e-interfaccia-applicativa-scope-2026-11-02-priorità-aggiornata-2026-11-09).

**Stato al 2026-11-10:** UI.01.1 completato con l’inventario statico riportato sotto. Il prossimo sotto-task è **UI.01.2**, percorsi e gerarchia desktop/mobile. Layout, componenti e nuove schermate rimangono da implementare.

## Ripartenza della prossima sessione

Leggere l’inventario UI.01.1 di questo brief, il piano e l’handoff. Definire in UI.01.2 i percorsi dashboard → lista → dettaglio → incidente e ritorno, preservazione dei filtri, contesti team/account/piattaforma e stati vuoti/caricamento/errore. Poi UI.01.3 realizzerà token e componenti con regole in `UI-GUIDELINES.md`; quel documento non viene anticipato dall’inventario.

La stima della fase A è 73–108 h incluso inventario, non un consuntivo. Le correzioni del collaudo sono implementate e l’utente conferma A–K conclusi; AppLayout e nuova dashboard non sono certificati da tali risultati.

La prima vista di riferimento è il dettaglio monitor dello screenshot descritto sotto; la sequenza utente è dashboard → lista → dettaglio → incidente e ritorno. L01–L08 sarà aggiornato ed eseguito in UI.06 dopo l’intera fase A. Per verifiche già eseguite e ambiente rimasto alla chiusura, leggere [BOOTSTRAP.md](BOOTSTRAP.md) e [TASKS.md](TASKS.md).

## Obiettivo per l’utente

Dopo l’accesso l’utente deve poter riconoscere il team attivo, capire se ci sono problemi, trovare il monitor interessato, leggere grafici e incidenti e compiere le azioni consentite dal proprio ruolo. Menu, ritorni e contesto devono evitare la necessità di ricordare URL o cercare un grafico in una pagina diversa dal monitor.

La prima porzione visibile da costruire dopo la definizione dei componenti è il percorso **layout comune → dashboard del team → lista monitor → dettaglio monitor**. Si completa poi la coerenza delle sezioni operative. Lo screenshot mostra un dettaglio monitor: il riepilogo dell’intero team e gli altri flussi vanno progettati esplicitamente, senza trattare quella singola immagine come il progetto di tutta l’applicazione.

## Cosa riprendere dal riferimento

| Elemento osservabile | Adattamento per l’applicazione | Fonte o vincolo |
|---|---|---|
| Menu laterale | Dashboard, Monitors, Incidents, Maintenance, Notifications, Status pages e Team, con sezione corrente evidente | Rotte esistenti e inventario di UI.01.1; un eventuale elenco manutenzioni team è una nuova proiezione autorizzata, non un nuovo motore |
| Intestazione monitor | Stato, nome, tipo, destinazione redatta, breadcrumb e azioni pertinenti | Presenter/policy monitor; un viewer non riceve azioni di modifica |
| Scheda stato | Up/Down/Unknown e stato operativo separato: pausa, sospensione, manutenzione | Non confondere ultimo risultato con attività o disponibilità attuale |
| Ultimo controllo | Data, tempo trascorso e frequenza configurata | Timestamp reali e dati mancanti espliciti; nessun finto aggiornamento |
| Barre ultime 24 ore | Andamento leggibile, dettaglio per intervallo e alternativa testuale | Semantica dichiarata: stati e buchi, non barre tutte verdi in assenza di osservazioni |
| Uptime per periodo | Percentuale, copertura, downtime e incidenti per finestra supportata | `MonitorAvailability`, dati grezzi/rollup e retention; non media di percentuali |
| Grafico tempi di risposta | Serie per regione/finestra, unità, campioni e tabella accessibile | Riutilizzo dei servizi M6/M7; distinguere assenza di misure, zero e disconnessione |
| Pannello certificato | Scadenza TLS osservata, quando applicabile | Il modello dispone di `ssl_expires_at`; censire proiezione e freschezza in UI.03.1. Non esiste per questo una funzione di scadenza registrazione dominio |
| Prossima manutenzione | Stato corrente e prossima finestra con collegamento alla gestione | Finestre esistenti, fuso esplicito e permessi di M4 |
| Destinatari/notifiche | Policy e canali effettivi, configurazione assente spiegata e collegamento utile | Nessun avatar/destinatario inventato o esposizione di credenziali |
| Comandi in alto | Modifica, pausa/ripresa e altre azioni realmente disponibili, con feedback | Policy, limiti e conferme esistenti. Il pulsante test-notifica del riferimento non introduce una nuova funzione |

Tema scuro, schede e accento per gli stati sono un punto di partenza. Colori propri, spaziatura, densità, etichette e disposizione verranno affinati durante l’implementazione. Nessun nuovo logo o decisione implicita sul nome: UI.07 rimane distinto e non blocca la dashboard.

## Struttura e percorsi

**Layout comune.** Sidebar desktop e menu mobile, team sempre identificabile, cambio team fra membership correnti, menu account/sicurezza e uscita. L’amministrazione della piattaforma è un contesto distinto e disponibile solo agli operatori. Le pagine di accesso e le pagine pubbliche mantengono layout dedicati.

**Dashboard team.** Sintesi operativa, monitor in difficoltà e incidenti aperti prioritari, ultimo aggiornamento e accessi alle azioni consentite. Primo utilizzo con una spiegazione e un ingresso alla creazione. Un account senza team ha un percorso chiaro per creare/selezionare un team o accettare un invito, secondo i flussi già implementati.

**Lista monitor.** Ricerca, filtri e paginazione conservati nell’URL. Stato distinguibile dal solo colore, tipo, destinazione sicura, ultimo check e azioni pertinenti. Clic sul nome apre il dettaglio completo con grafici; tornare alla lista conserva il contesto di ricerca.

**Dettaglio monitor.** Intestazione e comandi, schede, andamento 24 ore, grafici e statistiche, pannelli contestuali e incidenti recenti. A larghezza ridotta la gerarchia diventa una sequenza verticale utilizzabile; nessuna azione essenziale resta fuori schermo. La vecchia selezione dei grafici nella dashboard deve restare compatibile o avere un percorso di migrazione esplicito, senza rompere link esistenti.

**Flussi collegati.** Dall’incidente si torna al monitor e dal monitor si raggiungono manutenzione e notifiche. Le sezioni membri/inviti, status page e account diventano parti coerenti dell’applicazione. Gestione privata della status page, anteprima e pagina pubblica devono essere distinguibili.

## Regole che ogni porzione deve conservare

- Backend autorevole per ruoli, quote e isolamento: il menu non sostituisce le policy. Nuove letture/aggregazioni, se necessarie, restano dello stesso team e restituiscono proiezioni esplicite.
- Dati reali: finestre compatibili con creazione del monitor e retention; copertura/dati mancanti dichiarati. Nessun uptime 100%, data di scadenza, durata Up, destinatario o dato commerciale inventato.
- Servizi coesi per SLA, latenza e proiezioni; React presenta e gestisce l’interazione. Controller sottili, Form Request per nuovi filtri e nessuna regola duplicata nei widget.
- Reverb e recupero già implementati rimangono funzionanti senza polling periodico; un aggiornamento non deve sovrascrivere una bozza in modifica. Cambio team o revoca non lasciano dati del team precedente visibili.
- Validazione durante la digitazione dove implementata, errori vicino ai campi e annunci accessibili; conservazione del draft, invio singolo e password inline. Un problema di rete con esito incerto non autorizza un replay automatico.
- Ogni controllo è raggiungibile da tastiera, con etichetta e focus visibile. Menu mobile, grafici e tabelle hanno comportamento accessibile e alternativa testuale. Il collaudo finale è successivo, ma queste regole valgono fin dai primi componenti.
- Solo dipendenze motivate da una necessità concreta: React 19, Inertia 2, Tailwind 4 e componenti/grafici esistenti sono la base. Una nuova libreria richiede valutazione, compatibilità e aggiornamento della documentazione, non un’adozione implicita dal riferimento.

## Sequenza di lavoro e criteri di uscita

| Fase | Task operativi | Risultato verificabile |
|---|---|---|
| Inventario e progetto | UI.01.1–UI.01.3 | Dati/rotte/ruoli censiti, gerarchia desktop/mobile e componenti documentati, stima aggiornata e prima porzione definita |
| Struttura applicativa | UI.02.1–UI.02.4 | Navigazione e cambio team reali, menu mobile e contesti account/piattaforma corretti |
| Dashboard e monitor | UI.03.1–UI.03.8 | Overview, lista e dettaglio con metriche reali, grafici, azioni e realtime verificati |
| Sezioni operative | UI.04.1–UI.04.4 | Incidenti, manutenzioni, notifiche, status page, team/account e operatori integrati |
| Chiusura fase A | UI.06.1–UI.06.4 | Test pertinenti, smoke dei flussi modificati e L01–L08 sulla nuova UI; difetti bloccanti risolti |
| Completamento successivo | UI.04.5 e UI.05; UI.07 separato | Billing/API e pagine pubbliche integrati quando le relative funzioni esistono; identità definitiva o rinvio registrato prima di M12 |

La fase A precede M9 per decisione esplicita dell’utente. La fase successiva dipende da M9/M10 dove richiesto e non può essere una dipendenza della fase A: il piano deve restare eseguibile senza cicli. Le milestone funzionali originali, PDF e portale clienti restano nel perimetro.

Ogni sotto-task comprende implementazione, documentazione, test e controlli pertinenti; nessun rinvio dei test automatici a fine macro. Le misure di query e prestazioni devono essere riproducibili e reali. La valutazione dei tempi viene fatta con l’inventario: non si sommano nuove attività alle ore della specifica fingendo che fossero già comprese.

## Collaudo dopo la nuova interfaccia

Su richiesta dell’utente, **L è rinviato a UI.06**, dopo la dashboard e i flussi della fase A. Tutti gli otto casi vanno riferiti alla nuova interfaccia; i PASS già ottenuti restano evidenze della versione precedente, non certificano il nuovo layout.

Aggiornare [BROWSER-TESTS.md](BROWSER-TESTS.md) mantenendo gli ID esistenti e includendo dashboard e navigazione comune nella matrice delle schermate. Eseguire i casi A–K interessati dalle modifiche e L01–L08: tastiera, VoiceOver effettivo, mobile 390 px, zoom nativo 200%, doppio invio con rete lenta, offline/recupero, cronologia, console e confronto Brave/Firefox. Usare dati sintetici, registrare esiti e ripristini senza screenshot nel repository. La chiusura del collaudo non si deduce dalla sola build o da un mockup.

## Inventario verificato — UI.01.1, 2026-11-10

Inventario statico del codice, non collaudo runtime. UI.01.1 consegna questa mappa; non implementa AppLayout, nuovi widget o nuove query. I riferimenti sotto sono relativi al repository e descrivono il comportamento attuale. Le proposte restano assegnate ai sotto-task successivi.

### Schermate, ingressi e azioni

Nella tabella `T` significa `/teams/{team}`. Le pagine private elencate usano oggi `AuthLayout`, con menu locali; non esiste AppLayout. Le rotte team richiedono autenticazione, email verificata e `EstablishTeamContext`: il team nell’URL è autorevole rispetto alla preferenza salvata. Gli identificatori annidati restano soggetti ai binding tenant.

| Contesto e viste React | GET e ingresso server | Azioni e collegamenti attuali | Gap per il refactoring |
|---|---|---|---|
| `Dashboard/Show` | `T/dashboard`; [dashboard routes](../routes/dashboard.php), `DashboardController` | Riepilogo, incidenti, lista paginata, selezione grafici con `page`/`monitor`, download report mensile | Menu locale senza status page; grafici separati dal dettaglio, manca ingresso rapido di creazione autorizzato |
| `Monitors/Index` | `T/monitors`; [monitor routes](../routes/monitors.php), `MonitorPageController::index`, `ListMonitorsRequest` | Ricerca `q`, filtri `type`/`status`/`state`, pagina, quota e creazione; nome apre dettaglio | Ritorno dal dettaglio costruito senza filtri; summary senza destinazione |
| `Monitors/Create`, `Monitors/Edit` | `T/monitors/create`, `T/monitors/{monitor}/edit`; stesso controller | POST creazione, PUT/PATCH modifica; form per tipo, limiti intervallo e ritorni | Integrare layout preservando draft, segreti non riproiettati e validazione inline |
| `Monitors/Show` | `T/monitors/{monitor}`; `MonitorPageController::show` | Modifica, pausa/ripresa, DELETE con conferma, credenziale heartbeat POST/DELETE, incidenti recenti, manutenzioni, escalation | Nessuna serie metrica nel payload; TLS non proiettato; destinazione da proiezione sicura del dettaglio |
| `Incidents/Index`, `Incidents/Show` | `T/incidents`, `T/incidents/{incident}`; [incident routes](../routes/incidents.php), `IncidentPageController` | Filtri/pagina, ritorno al monitor; acknowledge, update, publish, severity, root-cause; timeline e massimo 100 consegne | Unificare navigazione e ritorni; non presentare il log limitato come storico completo |
| Manutenzioni, senza pagina autonoma | Pannello in `Monitors/Show`; `MaintenanceWindowController`, `MaintenanceSchedule` | POST/PATCH/DELETE sotto `T/monitors/{monitor}/maintenance`; modifica futura, fine anticipata corrente | Manca elenco team e relativa GET; Maintenance entra nel menu soltanto in UI.04.2 |
| `Notifications/Index` | `T/notifications`; [notification routes](../routes/notifications.php), `NotificationPageController` | CRUD canali/policy, verifica, quiet hours, rotazione segreto; assegnazione policy nel monitor; consegne nel dettaglio incidente | Menu comune, accesso guidato ai log esistenti; elenchi canali/policy caricati interamente, da considerare nelle misure UI.03.1/UI.04.2 |
| `StatusPages/Index` | `T/status-pages`; [status routes](../routes/status-pages.php), `StatusPageController` | Creazione/modifica/eliminazione, componenti, tema/password/pubblicazione e gestione/verifica dominio; apertura URL pubblico | Gestione privata e pagina pubblica devono restare distinguibili; non esiste pagina separata di modifica |
| `Teams/Index`, `Teams/Show` | `/teams`, `T`; [team routes](../routes/teams.php), `TeamController` | POST `T/select`, PATCH/DELETE membri, inviti POST/reinvio/DELETE; link a sezioni team e ownership | Selettore non condiviso; select redirige oggi a `/account`, non alla dashboard. Lista team risolve il ruolo con query per membership: punto da misurare, non N+1 dichiarato risolto |
| `Teams/Ownership` | `T/ownership`; `TeamController::ownership` | Trasferimento POST con password recente a un altro owner esistente | Preservare ritorno dopo riconferma e invarianti sul proprietario primario |
| `Account/Overview`, `Account/Security` | `/account`, `/account/security`; [auth routes](../routes/auth.php), `AccountController` | Team correntemente accessibile, invito pendente, logout POST; setup/conferma/disattivazione 2FA, recovery, password | Overview espone solo nome del team; manca ID per ingresso diretto alla dashboard. Security richiede password recente; credenziali lette separatamente via AJAX no-store |
| `Admin/Teams/Index`, `Admin/Teams/Show` | `/admin/teams`, `/admin/teams/{team}`; [admin routes](../routes/admin.php), `TeamDirectoryController` | Directory ricercabile/paginata, dettaglio, audit/override; PUT/DELETE override con riconferma inline | Contesto piattaforma distinto dal team, non accessibile tramite solo ruolo owner |
| `Teams/Invitation`, `Teams/Register` | `/invitations/accept`, `/invitations/register`, link bearer `/invitations/{invitation}/{token}`; `InvitationController`, `InvitedRegistrationController` | Revisione, cambio account, registrazione invitata, verifica email e accettazione esplicita | Flusso di ingresso con AuthLayout/AuthForm, fuori dal layout tenant; nessuna accettazione implicita |

Confini censiti: `Home`, le sette pagine `Auth/*` (Login, Register, ForgotPassword, ResetPassword, VerifyEmail, ConfirmPassword, TwoFactorChallenge) e `StatusPages/Public`, `StatusPages/Unlock`, `StatusPages/Subscription` rimangono nei flussi pubblici/accesso. Registrazione ordinaria crea il team iniziale; registrazione invitata no. Non esiste una rotta autonoma per creare un ulteriore team dall’account: lo stato senza team può offrire selezione o revisione invito esistenti, senza inventare un pulsante di creazione funzionante. Billing e token API non hanno ancora schermate operative.

### Permessi e autorità

Fonti backend: [MonitorPolicy](../app/Policies/MonitorPolicy.php), [IncidentPolicy](../app/Policies/IncidentPolicy.php), [NotificationChannelPolicy](../app/Policies/NotificationChannelPolicy.php), [EscalationPolicyPolicy](../app/Policies/EscalationPolicyPolicy.php), [StatusPagePolicy](../app/Policies/StatusPagePolicy.php), [TeamPolicy](../app/Policies/TeamPolicy.php), [TeamInvitationPolicy](../app/Policies/TeamInvitationPolicy.php) e [TeamAccess](../app/Services/Identity/TeamAccess.php). Le mutation action ricontrollano l’autorità corrente sotto lock; nascondere il comando non sostituisce tali controlli.

| Capacità | Owner | Admin | Member | Viewer | Indicazione frontend verificata |
|---|---|---|---|---|---|
| Leggere dashboard/monitor/incidenti/notifiche/status page private del team | Sì | Sì | Sì | Sì | Membership nelle rotte/request; letture consentite anche senza comandi |
| Creare/modificare/eliminare/pausare/riprendere monitor, gestire heartbeat e assegnazione policy | Sì | Sì | Sì | No | `can.create`, `can.update/delete/pause/resume`; quota distinta dal ruolo |
| Gestire manutenzioni | Sì | Sì | Sì | No | `can.update` del monitor, stesso gate in request/action; nessuna policy manutenzione distinta |
| Prendere in carico, annotare, pubblicare aggiornamenti, cambiare severità/causa incidente | Sì | Sì | Sì | No | `can.update`; applicabilità ulteriore secondo stato incidente |
| Gestire canali, segreti, quiet hours e policy escalation | Sì | Sì | No | No | `can.manage`; assegnare una policy al monitor segue invece MonitorPolicy |
| Gestire status page, componenti e dominio | Sì | Sì | No | No | `can.manage`, quota pagine separata |
| Gestire membri e inviti | Tutti i ruoli, con invarianti di dominio | Solo member/viewer, sia ruolo attuale sia destinazione | No | No | `roles`, `manageable`, inviti filtrati dal controller |
| Lasciare il team | Con vincoli di ownership | Sì | Sì | Sì | Controllo self separato; proprietario primario protetto |
| Trasferire ownership primaria | Solo proprietario primario | No | No | No | `ownerId === actorId`, successore owner esistente, password recente |
| Selezionare un team accessibile, account e sicurezza propri | Sì | Sì | Sì | Sì | Membership riletta, preferenza revocata risolta come assente |

L’operatore non è un quinto ruolo tenant: [PlatformAccess](../app/Services/Admin/PlatformAccess.php) e [EnsurePlatformAdmin](../app/Http/Middleware/EnsurePlatformAdmin.php) verificano flag persistito/email e 2FA confermata in produzione; non operatori ricevono 404. Il link Admin deriva dal flag della propria identità, le PUT/DELETE richiedono anche password recente. [HandleInertiaRequests](../app/Http/Middleware/HandleInertiaRequests.php) condivide nome prodotto, identità minima, status e coordinate pubbliche realtime, ma non elenco membership/ruolo/team attivo: UI.02 dovrà definire una proiezione condivisa sicura. Nessuna discrepanza di autorizzazione è stata dimostrata dall’inventario statico; le regressioni runtime restano da provare.

### Componenti riutilizzabili

| Gruppo | Fonte esistente | Riutilizzo e limite |
|---|---|---|
| Layout e stile | [AuthLayout](../resources/js/components/auth/AuthLayout.tsx), [app.css](../resources/css/app.css) | Tema slate/emerald e width narrow/wide; marchio attuale hardcoded, token semantici assenti. AppLayout separato e nome da configurazione nei task UI.01.3/UI.02 |
| Form e sicurezza | `components/auth/AuthForm`, `PasswordConfirmationForm`; `monitors/MonitorForm`, `intervalValidation`; form notifications/status-pages | Conservare errori per campo, draft, disable durante invio e password inline. Non creare un secondo motore form |
| Stati e tabelle | `monitors/MonitorBadges`, `incidents/IncidentBadges`, `IncidentTable`, `TableScrollArea`, `monitors/Pagination` | Etichette oltre al colore, contenimento orizzontale e paginazione; manca ancora una famiglia condivisa di button/input/card/alert |
| Grafici e report | [TimeSeriesChart](../resources/js/components/dashboard/TimeSeriesChart.tsx), `MonthlyReportDownload` | SVG, selezione serie, null come buchi, tabella equivalente e UTC; nessun calcolo SLA nel browser. Barre stato 24 h da costruire |
| Pannelli dominio | `incidents/MaintenancePanel`, `notifications/EscalationPanel`, `DeliveryLog`, `monitors/HeartbeatPanel`, form dominio/status page | Conservare dati redatti e distinzioni pubblico/privato; proiezione escalation monitor oggi contiene policy effettiva, non elenco canali effettivi |
| Rete e realtime | `RequestFailureNotice`, `LiveUpdates`, [useLivePage](../resources/js/hooks/useLivePage.ts), [LivePageSession](../resources/js/realtime/LivePageSession.ts) | Recupero autorizzato event-driven, senza polling; root specifiche per dashboard, lista/dettaglio monitor e incidenti. Nuovi payload metrici richiedono aggiornamento esplicito delle root recuperate e test di revoca/cambio team; form locali non vanno sovrascritti |

### Mappa riferimento → dati → gap

| Scheda o comportamento | Fonte verificata e semantica attuale | Classificazione e destinazione |
|---|---|---|
| Stato, nome, tipo, frequenza, ultimo check | [MonitorPresenter](../app/Http/Presenters/Monitoring/MonitorPresenter.php) summary/detail; `MonitorOperationalState` separa active/paused/suspended da lastStatus | Disponibile; UI.03.4 aggiunge presentazione, senza equiparare ultimo esito a disponibilità corrente |
| Destinazione sicura e comandi | `MonitorConfigurationProjection` nel dettaglio, `can` dai gate, `MonitorLimits`/`TeamEntitlement` per quota | Da estendere per la lista in UI.03.1, senza decrittare/esporre configurazioni indiscriminatamente; comandi già presenti |
| Conteggi team e incidenti aperti | [DashboardOverview](../app/Services/Dashboard/DashboardOverview.php): conteggi disgiunti, sospensione prima della pausa; incidenti massimo cinque, più conteggio totale | Disponibile; UI.03.2 riordina priorità, non calcola uptime team con medie percentuali |
| Barre 24 h e uptime | [MonitorAvailability](../app/Services/Metrics/MonitorAvailability.php) `hourly`/`between`, `AvailabilityReport`, `ExclusionPeriods`; dashboard proietta percentuali/copertura/SLA e durate aggregate | Da estendere: le percentuali orarie non sono una timeline Up/Down. Definire bucket con dati mancanti/esclusioni in UI.03.1 e resa UI.03.4, senza trasformare un’ora parzialmente osservata in verde pieno |
| Latenza e regione | [DashboardLatency](../app/Services/Dashboard/DashboardLatency.php): 24 bucket orari mobili, medie di misure observed per EU/US e campioni, null distinto da zero | Disponibile solo nella dashboard; UI.03.1/UI.03.5 porta la lettura al dettaglio. 7/30 giorni non sono già supportati da questo servizio |
| Uptime per finestra, downtime e copertura | `MonitorAvailability::between`: durate temporali, vita del monitor, pause/manutenzioni/sospensioni; ore intere dai rollup e bordi dal grezzo | Servizio disponibile, nuova proiezione UI.03.1. Retention grezza leggibile 30 giorni anche con partizioni fisiche presenti; storia rollup secondo piano. Finestra non ricostruibile interamente indisponibile |
| p50/p95 e storico esteso | [RollupStore](../app/Services/Metrics/Rollups/RollupStore.php), [HourlyRollupCalculator](../app/Services/Metrics/Rollups/HourlyRollupCalculator.php), rollup orari/giornalieri | Dati aggregati esistenti, contratto grafico esteso assente; verificare statistiche e aggregabilità in UI.03.1, senza mediare percentili o medie di medie |
| Scadenza certificato | [ExecutionResults](../app/Services/Monitoring/Results/ExecutionResults.php) aggiorna `ssl_expires_at` solo per TLS e conserva il valore quando manca una nuova lettura; risultati hanno `tls_expires_at` | Campo disponibile ma non proiettato. UI.03.1 deve identificare la provenienza/freschezza osservata: `last_checked_at` da solo non prova una nuova lettura certificato. UI.03.6 mostra null/obsoleto onestamente |
| Manutenzione corrente/prossima | [MaintenanceSchedule](../app/Services/Incidents/MaintenanceSchedule.php) e `MaintenanceWindowPresenter`; dettaglio riceve fino a 50 finestre in corso/future e cinque terminate | Disponibile per monitor; distinguere fase corrente dalla prossima in UI.03.6. Elenco team assente, da aggiungere in UI.04.2 |
| Notifiche effettive | [NotificationPresenter](../app/Http/Presenters/Notifications/NotificationPresenter.php)::monitorEscalation risolve override/default o nessuna policy; pagina Notifications proietta canali e step | Da estendere con step/canali effettivi redatti in UI.03.1/UI.03.6; una policy selezionata non garantisce una consegna. Non esporre credenziali o inventare destinatari |
| Incidenti recenti | `IncidentListing::recentForMonitor`, `IncidentPresenter`, massimo cinque nel dettaglio | Disponibile; UI.03.6/UI.04.1 curano collegamenti, durata e stato reali |
| Ultimo aggiornamento e connessione | `dashboard.generatedAt`, timestamp monitor e fase `useLivePage` | Disponibile, distinguere generazione proiezione, osservazione e connessione |
| Durata continua Up, scadenza registrazione dominio, avatar destinatari, test-notifica | Nessuna proiezione/funzione affidabile censita | Assenti dal contratto corrente: non introdurre tali funzioni per imitare lo screenshot |

Compatibilità da preservare: `T/dashboard?page=…&monitor=…` continua a identificare i grafici selezionati; spostarli nel dettaglio non autorizza la rimozione di questi URL. La ricerca monitor resta nell’URL (`q`, `type`, `status`, `state`, `page`); l’attuale “Back to monitors” perde tale selezione. Il meccanismo di ritorno sarà definito in UI.01.2, senza accettare destinazioni esterne arbitrarie.

### Porzioni, dipendenze e stima aggiornata

Stime ingegneristiche iniziali in ore, non misure di tempo eseguito né impegno di calendario. Ogni intervallo include implementazione/documentazione e verifiche pertinenti; UI.06 aggiunge il collaudo trasversale, non sostituisce i test per porzione.

| Ordine | Porzione | Sviluppo/documentazione | Verifiche/collaudo | Totale e dipendenza |
|---|---|---:|---:|---|
| 1 | UI.01.1 inventario | 2–3 h | 1 h | 3–4 h; M1–M8 |
| 2 | UI.01.2 percorsi e gerarchia | 2–3 h | 1 h | 3–4 h; inventario |
| 3 | UI.01.3 token e componenti di base | 4–6 h | 2–3 h | 6–9 h; flussi, regole in UI-GUIDELINES |
| 4 | UI.02.1–UI.02.4 layout, team, mobile e migrazione | 7–10 h | 4–6 h | 11–16 h; UI.01 |
| 5 | UI.03.1 letture, contratti e misure | 5–8 h | 3–4 h | 8–12 h; layout definito e fonti metriche |
| 6 | UI.03.2–UI.03.3 overview/lista | 4–6 h | 2–3 h | 6–9 h; layout e proiezioni |
| 7 | UI.03.4–UI.03.6 dettaglio/grafici/pannelli | 7–10 h | 4–6 h | 11–16 h; letture e overview/lista |
| 8 | UI.03.7–UI.03.8 azioni/realtime | 3–5 h | 3–4 h | 6–9 h; verifica incrementale anche durante le porzioni 6–7 |
| 9 | UI.04.1–UI.04.4 sezioni operative | 7–11 h | 4–6 h | 11–17 h; UI.02, coordinato con UI.03 |
| 10 | UI.06.1–UI.06.4 accettazione e ripristini | 2–3 h | 6–9 h | 8–12 h; fase A funzionale completa |
| | **Fase A complessiva stimata** | **43–65 h** | **30–43 h** | **73–108 h**, incluso inventario |

L’elenco Maintenance si implementa nella porzione 9 e solo allora entra nella sidebar: nessuna dipendenza circolare con UI.02. Il prossimo passo concreto è UI.01.2, poi UI.01.3; non si salta direttamente a nuovi controller o al restyling delle pagine. Fase B (UI.04.5/UI.05), identità UI.07 e lavoro M9–M12 non sono inclusi nella stima.

La specifica somma 178 h per M0–M12: l’aggiunta della fase A porta la previsione di perimetro a **251–286 h**, non le ore residue né il consuntivo; non include eventuali altre estensioni, fase B o ristime delle milestone originali. Nessuna previsione di 8–9 settimane viene riconfermata. Rivedere gli intervalli dopo layout e prime query: i principali fattori d’incertezza sono freschezza TLS, serie storiche estese, liste attualmente non paginate e accettazione con VoiceOver/browser reali.

### Verifiche richieste alle porzioni successive

Riutilizzare le suite DOM Dashboard/Monitors/Incidents/Notifications/StatusPages/Teams/Admin, i test RealtimePages/RealtimeAcceptance/LivePageSession e le integrazioni DashboardTest, MonitorPagesTest, MonitorAvailabilityTest, MaintenanceWindowTest, TeamAuthorizationMatrixTest e PlatformAdminAccessTest secondo le parti toccate. Aggiungere test per nuove proiezioni/rotte, dati mancanti, retention, dinieghi viewer/non membro e isolamento tenant. In UI.03.1 misurare query/righe/tempi su fixture riproducibili prima e dopo: questo inventario non produce benchmark o attestazioni di assenza di N+1.

Per UI.01.1: lettura statica e verifica documentale, senza avvio servizi, interrogazione dati, screenshot o esecuzione delle suite applicative. Le evidenze del 2026-11-09 restano storiche. UI.01.2/UI.01.3 e la fase A restano aperte; L01–L08 rimane in UI.06.
