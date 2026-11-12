# Collaudo della nuova UI — L01–L08, 2026-11-12

**UI.06.3 completato.** L01–L08 verificati nei perimetri e limiti sotto: L02 tramite i riscontri
VoiceOver dell’utente sui due moduli in entrambi i browser, L03 tramite il seguito di zoom nativo.
Gli annunci non sono dedotti da ARIA, screenshot o viewport e non sono stati ascoltati dall’agente.
L’accettazione della fase A rimane il successivo UI.06.4; M9 non è avviato.

**Riscontro finale:** alla richiesta di ripetere monitor e canale in Firefox con VoiceOver attivo,
l’utente conferma «ok funziona anche su Firefox». I quattro controlli L02 sono positivi secondo
l’utente. Non viene dedotta la trascrizione esatta degli annunci. Le versioni installate sono state
ricontrollate alla chiusura (Brave 157.1.99.56, Firefox desktop 159.0.1); l’utente non ha indicato
separatamente la versione della sua finestra. Le note cronologiche sotto mantengono i limiti intermedi.

## Riscontri manuali intermedi — registrati dalle 10:35 UTC

Alla richiesta di provare il modulo monitor in Brave con VoiceOver, l’utente risponde:
«funziona, mi ha segnalato del campo Name vuoto». Il primo controllo L02 è quindi positivo secondo
il riscontro dell’utente sulla procedura richiesta; non è un ascolto dell’agente né una trascrizione
completa dell’annuncio. La versione browser non è stata comunicata. Restano da osservare il modulo
canale nello stesso browser e i due moduli in Firefox; **L02/UI.06.3 non sono ancora completi**.
Il JSON conserva questo singolo riscontro in user_observations (ora di registrazione, provenienza,
procedura/browser richiesti, testo riferito e limiti), separato dalle sonde automatiche.

**Secondo riscontro:** alla successiva prova canale Email senza Name, sempre nella procedura Brave
con VoiceOver, l’utente risponde «ok funziona». Entrambi i controlli Brave sono positivi secondo
l’utente. Restano i due moduli in Firefox; nessuna versione o trascrizione dell’annuncio è dedotta.
Questo seguito aggiorna il punto precedente, senza dichiarare L02 completo.

## Versioni, ambiente e schermate

Prove dell’agente tra **09:24 e 09:40 UTC** con Brave **157.1.99.56 / Chromium 157.0.8103.50**
e Firefox **159.0 Playwright**, headless con profili effimeri. Firefox desktop installato 159.0.1
non è il binario collaudato. Origine `http://localhost:8000`; form, sessioni, PostgreSQL e Redis
reali su fixture sintetiche; nessuna sessione costruita nel database o risposta applicativa simulata.

Sono state attraversate **11 schermate in entrambi i browser**: dashboard, lista/dettaglio monitor,
incidente, canali/policy, manutenzioni, consegne, gestione status page, team, account e pubblico anonimo.
Su ciascuna: Tab nativo, apertura delle sezioni View … data/quiet hours con Invio ove presenti,
controllo degli stili di focus e misura del reflow a 390 × 844. Le due viste di dettaglio/grafici
Brave sono state anche ispezionate visivamente tramite screenshot temporanei, senza conservarli
nel repository. Questa ispezione limitata non certifica tutte le percezioni visive o annunci assistivi.

Solo web/PostgreSQL/Redis sono stati avviati; scheduler, worker e Reverb restano fermi. Lo schema
è quello già applicato in UI.06.2; nessuna migration, `.env`, chiave, dipendenza, lockfile o sorgente
applicativo modificato. Tutti gli script/browser di prova sono sotto `/private/tmp`, fuori dai manifest.
`npm run check` passa (ESLint, Prettier, TypeScript e cinque test qualità). Il build della stessa
versione è già passato in UI.06.2; suite PHP/DOM, PHP-CS-Fixer/Larastan e CI remota non ripetuti
per queste sole modifiche Markdown/evidenza.

## Esiti per caso, senza alterare i risultati storici

| Caso | Esito corrente | Evidenza e limite |
|---|---|---|
| L01 | PASS | Tab sulle 11 schermate in entrambi i browser, outline calcolato presente; filtro e apertura/chiusura editor con tastiera, frecce nello scroll locale della tabella mobile. Menu account Escape, conferme Delete/Publish con focus iniziale su Cancel/Cancel publication ed Escape al trigger senza POST. Dialogo mobile nativo: nessun controllo di sfondo raggiunto, Escape e scroll ripristinati. Non è una prova con tecnologia assistiva. |
| L02 | PASS, riscontro dell’utente | Monitor HTTP senza Name e canale Email senza Name: parte visiva/focus/ARIA già verificata nelle sonde; l’utente conferma la segnalazione VoiceOver su entrambi i moduli in Brave e Firefox. Riscontri distinti nel JSON, senza ascolto dell’agente né trascrizione esatta degli annunci. |
| L03 | PASS, seguito nativo registrato | Le 22 misure iniziali a 390 px passano; il seguito verifica 11 schermate per browser al fattore nativo 2. Brave tabs.getZoom() = 2; Firefox desktop fullZoom = 2 e indicatore browser 200%. CSS zoom resta 1, larghezza CSS 720 su finestra 1440; nessun controllo fuori pagina salvo tabelle con scroll locale. Ripristino al fattore 1. Metodi e limiti sotto. |
| L04 | PASS in Brave | Rallentamento nativo Chromium CDP: 150 ms, download 200000 B/s (1,6 Mbps), upload 93750 B/s (750 Kbps). Doppio clic reale su Add update: Saving…, disabilitato, **un solo POST**, una sola nota privata dopo reload. Throttle ripristinato. Questo specifico profilo lento non è stato replicato in Firefox. |
| L05 | PASS in Brave e Firefox | Offline reale del contesto browser: avviso globale e Outcome not confirmed, bozza conservata, invio bloccato. Ritorno online senza replay; lettura in seconda scheda conferma nota assente. I checked the saved data non scrive; un solo reinvio esplicito salva una nota, unica dopo reload. |
| L06 | PASS in Brave e Firefox | Lista resolved → dettaglio, Back to incidents, Indietro/Avanti e reload conservano nota/contesto senza POST. Con bozza non inviata: Stay conserva testo e URL; Discard and leave esce senza salvare. |
| L07 | PASS per errori JS inattesi, con osservazioni | Nessuna eccezione pageerror nei flussi definitivi; errore di trasporto offline e connessioni WebSocket rifiutate con Reverb fermo distinti. Brave richiede anche `/favicon.ico`, assente: 404 di risorsa, non eccezione JavaScript; osservazione conservata per UI.06.4/identità futura, nessun nuovo logo introdotto. Non si dichiara una Console priva di ogni messaggio. |
| L08 | PASS nel perimetro della richiesta iscrizione | Confronto login/filtro/modifica nome con ripristino/lettura incidente/pubblico già osservato nei due browser in [UI.06.2](BROWSER-RESULTS-2026-11-12.md), stessa build senza modifiche applicative; ora aggiunta richiesta email anonima nuova per browser su pagina temporanea, con risposta generica e campo ripulito. **Nessuna conferma email o consegna provider attestata** (H28–H37 sono prove distinte). Pagine temporanee eliminate e record dipendenti rimossi dal vincolo cascade; realtime facoltativo non scelto. |

I [dati JSON](browser-evidence/2026-11-12/UI06-3/verification.json) conservano **64 sonde circoscritte
superate**, inclusi i sotto-controlli visivi di L02 e mobile di L03: non attestano annunci assistivi.
Il successivo native-zoom.json completa L03, separato dalle 64 sonde iniziali. Il documento descrive ogni chiave del JSON: scope/origin/browsers/mode identificano
l’ambiente, case_assessment lo stato L, successful_subchecks ora/browser/schermata/evidenza/esito,
console_attribution i messaggi attribuiti, exploration_limits i limiti delle sonde iniziali,
retained_private_note_ids gli effetti conservati, cleanup il ripristino e native_checks_pending la guida.
Non sono conservati cookie, credenziali, URL firmati o payload di sottoscrizione.

## Seguito: zoom nativo 200% completato — 10:00–10:05 UTC

Le API macOS di accessibilità restano disabilitate, ma il controllo dello zoom del browser è
raggiungibile attraverso i protocolli browser. Questa strada completa L03 senza usare CSS zoom,
pinch zoom o emulazione del device scale. È una scelta di metodo documentata, non una riduzione
al test a 390 px né una dichiarazione di click sul menu macOS.

- **Brave 157.1.99.56 / Chromium 157.0.8103.50:** estensione locale minimale, solo profilo temporaneo,
  tabs.setZoom(2) e tabs.getZoom() = 2. Finestra gestita dal browser, senza viewport emulato; dimensioni
  CSS 720 e devicePixelRatio 2, CSS zoom 1. Editor notifiche/status page e tabella equivalente aperti;
  focus e controlli dentro il viewport salvo scorrimento locale. Pubblico in profilo anonimo separato.
- **Firefox desktop 159.0.1**, questa volta il binario installato: client ufficiale Mozilla Marionette
  3.7.1 installato soltanto in `/private/tmp`, nuovo profilo isolato. ZoomManager imposta il fullZoom
  del browser a 2; l’indicatore nativo della barra indirizzi riporta **200%** su ogni schermata.
  Stesse 11 schermate, editor/tabelle aperti e controlli entro la pagina; pubblico senza cookie del
  profilo temporaneo. Non è il Firefox Playwright della prima esecuzione.
- **22 controlli superati**, 11 per browser; fattore iniziale 1 e finale 1, browser effimeri chiusi.
  Nessuna impostazione del profilo personale, dato applicativo, pacchetto/lockfile del progetto o
  autorizzazione macOS modificata. Screenshot temporanei ispezionati fuori repository: tabella
  ingrandita in Brave e indicatore 200% in Firefox. Non certificano annunci o percezioni assistive.

Dati in [native-zoom.json](browser-evidence/2026-11-12/UI06-3/native-zoom.json): scope/date/browsers
identificano il seguito, methods/sources il metodo e i riferimenti, checks le singole schermate,
restoration il ripristino e limits i confini. Riferimenti primari:
[API Tabs Chromium](https://developer.chrome.com/docs/extensions/reference/api/tabs/#method-setZoom),
[Marionette Mozilla](https://firefox-source-docs.mozilla.org/remote/marionette/Intro.html).

Il primo screenshot con viewport emulato risultava bianco ed è escluso dall’evidenza visiva;
la ripetizione usa dimensioni finestra del browser e acquisizione diretta. Il client Mozilla ha
emesso warning Python dei distruttori del profilo allo shutdown, dopo ripristino e cleanup:
sono limiti dello strumento temporaneo, non eccezioni JavaScript del prodotto. Nessuna immagine
è conservata nel repository. **L’agente non ha ascoltato VoiceOver; i riscontri finali dell’utente completano L02 come registrato in apertura.**

## Ripetizioni e attribuzione delle sonde

Le prime sonde cercavano il main prima dell’idratazione pubblica, più di due controlli su una pagina
che ha soltanto Email address e Subscribe to updates, oppure Cancel/region invece di Cancel publication/group.
Sono state corrette e ripetute. Al confine del ciclo Tab di un dialogo nativo i browser espongono anche
body/dialog come elemento attivo: non è un controllo di sfondo. La verifica definitiva controlla
l’assenza di focus sui controlli dietro il dialogo e il ritorno con Escape, senza dedurre da body il
focus dell’interfaccia macOS.

Un primo proxy HTTP temporaneo ha prodotto trasferimenti incompleti/annullamenti e risultati
incoerenti: **non è evidenza valida per accettare o rifiutare L04**. Il collaudo definitivo usa
rallentamento nativo Chromium e connessione diretta; offline e history sono ripetuti direttamente
in entrambi i browser. Alcune note dei tentativi iniziali erano comunque state accettate dal server:
sono inventariate sotto, senza fingere rollback o cancellarle dalla timeline append-only.
Una prima asserzione Console troppo ampia trattava ogni risorsa 404 come errore JS; la ripetizione
attribuisce esattamente il favicon mancante e distingue le eccezioni non gestite, che sono zero.

## Dati conservati e ripristino

- Sette nuove note **private**, con ID **46–52** sull’incidente sintetico 9: una del primo tentativo
  L04, due delle ripetizioni offline, una diagnostica nativa, una L04 definitiva e due L05 definitive.
  Ognuna è unica col proprio testo; `is_public = false`, `published_at = null`. L’incidente resta Resolved.
  Queste sette si aggiungono alle due note UI.06.2, senza pubblicazioni o invii provider.
- Le due pagine temporanee `ui06-l08-brave-20261112` e `ui06-l08-firefox-20261112` sono state create
  via UI, visitate anonimamente, usate per la richiesta generica e cancellate via UI. Il vincolo
  cascade rimuove subscriber e outbox dipendenti; nessun worker ha consegnato email. Le pagine
  originarie Ruoli Alpha/Ruoli Admin rimangono bozze inalterate; Stato Prova soltanto letta.
- Monitor originari 7/8 ancora in pausa con i nomi originali; nessun override Alpha/Beta; owner
  sintetico non operatore. Nessun cambio membership, credenziale, piano o trial.
- Profili effimeri chiusi, proxy temporanei arrestati, rete online e throttling ripristinati;
  VoiceOver e zoom dei profili personali non modificati; zoom dei profili temporanei ripristinato a 1. Nessun Git/gh o screenshot nel repository.
- **Dopo i riscontri finali, web/PostgreSQL/Redis sono stati arrestati**, ripristinando lo stato
  iniziale; scheduler/worker/Reverb rimangono arrestati e i volumi sono conservati. Stato personale
  finale di VoiceOver non comunicato dall’utente: non viene dedotto o dichiarato ripristinato.
  Il controllo di tale preferenza e degli altri ripristini finali rimane nella chiusura UI.06.4.

## Consegna a UI.06.4

UI.06.3 completato con la conferma Firefox dell’utente. I riferimenti tecnici dei gate precedenti,
i 64 sotto-controlli iniziali, i 22 controlli zoom nativo e i riscontri VoiceOver sono separati:
nessun totale viene presentato come misura di coverage o accettazione produzione.

UI.06.4 deve valutare le osservazioni (favicon 404 incluso), verificare ripristini/dati/piano,
registrare lo stato finale personale di VoiceOver e aggiornare l’handoff. I servizi applicativi
sono nuovamente arrestati; lo stato iniziale dei volumi è conservato. **La fase A non è ancora
accettata e M9 non è avviato.**
