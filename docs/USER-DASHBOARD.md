# Dashboard utenti — brief e piano di realizzazione

Decisione dell’utente del 2026-11-09: dare priorità a una dashboard e a una navigazione che rendano fruibile l’applicazione, partendo dallo screenshot UptimeRobot allegato nella conversazione e adattandolo progressivamente. Il riferimento rimane nella conversazione: non viene copiato nel repository. Il piano operativo e gli stati sono nel [macro task UI](TASKS.md#ui--dashboard-utenti-e-interfaccia-applicativa-scope-2026-11-02-priorità-aggiornata-2026-11-09).

Questa consegna è pianificazione. Layout, componenti e nuove schermate non risultano implementati con l’aggiunta del documento. Il primo sotto-task da avviare è **UI.01.1**, inventario dei flussi/dati e dimensionamento delle porzioni.

## Ripartenza della prossima sessione

La sessione si chiude con **UI.PLAN completato** e **UI.01.1 ancora da iniziare**. Le correzioni funzionali del collaudo sono implementate e l’utente conferma A–K conclusi; la nuova dashboard, i nuovi componenti e AppLayout restano da realizzare. Non confondere i 27 sotto-task pianificati con lavoro già eseguito.

Output atteso di UI.01.1: inventario delle schermate/rotte/ruoli, elenco dei componenti riusabili, mappa scheda/grafico → servizio/dato reale, gap da coprire e stima/ordine delle porzioni. Aggiornare questo brief e TASKS con il risultato, poi proseguire UI.01.2/UI.01.3. Non è necessario riaprire L o M9 per iniziare l’inventario.

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
