# Collaudo della nuova UI — L01–L08, 2026-11-12

**UI.06.3 resta in corso.** Guida e mappa aggiornate, sei casi L verificati nel perimetro sotto;
**L02 è aperto per VoiceOver reale, L03 per zoom browser nativo 200%**.
I riscontri assistivi non sono dedotti da attributi ARIA, focus DOM, screenshot o viewport.
Le [istruzioni per completare le prove native](BROWSER-NATIVE-CHECKS.md) sono pronte.
UI.06.4 e l’accettazione della fase A non sono avviate; M9 resta successivo.

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
| L02 | BLOCCATO, parte visiva verificata | Due form per browser: monitor HTTP senza Name e canale Email senza Name. Errore vicino al campo, aria-invalid e associazione verificati; primo form con focus su Name, nessuna creazione. **Nessun annuncio VoiceOver ascoltato**. L’API macOS UI elements enabled restituisce false; non sono state cambiate autorizzazioni/preferenze di sistema. |
| L03 | BLOCCATO, parte 390 px verificata | Tutte le 22 misure hanno documentWidth = bodyWidth = viewport = 390; nessun controllo visibile fuori viewport tranne contenuto nelle tabelle a scorrimento locale. Nessun CSS zoom o device scale viene chiamato “200%”: **zoom nativo non osservato**. |
| L04 | PASS in Brave | Rallentamento nativo Chromium CDP: 150 ms, download 200000 B/s (1,6 Mbps), upload 93750 B/s (750 Kbps). Doppio clic reale su Add update: Saving…, disabilitato, **un solo POST**, una sola nota privata dopo reload. Throttle ripristinato. Questo specifico profilo lento non è stato replicato in Firefox. |
| L05 | PASS in Brave e Firefox | Offline reale del contesto browser: avviso globale e Outcome not confirmed, bozza conservata, invio bloccato. Ritorno online senza replay; lettura in seconda scheda conferma nota assente. I checked the saved data non scrive; un solo reinvio esplicito salva una nota, unica dopo reload. |
| L06 | PASS in Brave e Firefox | Lista resolved → dettaglio, Back to incidents, Indietro/Avanti e reload conservano nota/contesto senza POST. Con bozza non inviata: Stay conserva testo e URL; Discard and leave esce senza salvare. |
| L07 | PASS per errori JS inattesi, con osservazioni | Nessuna eccezione pageerror nei flussi definitivi; errore di trasporto offline e connessioni WebSocket rifiutate con Reverb fermo distinti. Brave richiede anche `/favicon.ico`, assente: 404 di risorsa, non eccezione JavaScript; osservazione conservata per UI.06.4/identità futura, nessun nuovo logo introdotto. Non si dichiara una Console priva di ogni messaggio. |
| L08 | PASS nel perimetro della richiesta iscrizione | Confronto login/filtro/modifica nome con ripristino/lettura incidente/pubblico già osservato nei due browser in [UI.06.2](BROWSER-RESULTS-2026-11-12.md), stessa build senza modifiche applicative; ora aggiunta richiesta email anonima nuova per browser su pagina temporanea, con risposta generica e campo ripulito. **Nessuna conferma email o consegna provider attestata** (H28–H37 sono prove distinte). Pagine temporanee eliminate e record dipendenti rimossi dal vincolo cascade; realtime facoltativo non scelto. |

I [dati JSON](browser-evidence/2026-11-12/UI06-3/verification.json) conservano **64 sonde circoscritte
superate**, inclusi i sotto-controlli visivi di L02 e mobile di L03: non significano che quei due casi
siano completi. Il documento descrive ogni chiave del JSON: scope/origin/browsers/mode identificano
l’ambiente, case_assessment lo stato L, successful_subchecks ora/browser/schermata/evidenza/esito,
console_attribution i messaggi attribuiti, exploration_limits i limiti delle sonde iniziali,
retained_private_note_ids gli effetti conservati, cleanup il ripristino e native_checks_pending la guida.
Non sono conservati cookie, credenziali, URL firmati o payload di sottoscrizione.

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
  VoiceOver/zoom del sistema non modificati. Nessun Git/gh o screenshot nel repository.
- **Web/PostgreSQL/Redis restano avviati per le prove native dell’utente**; scheduler/worker/Reverb
  rimangono arrestati. È una differenza intenzionale rispetto allo stato iniziale tutto arrestato.
  Dopo le osservazioni native ripristinare lo stato iniziale con `docker compose stop web postgres redis`,
  salvo che l’utente scelga di tenere attivo lo sviluppo. I volumi non vanno rimossi.

## Cosa manca per chiudere UI.06.3

Registrare versione desktop, annuncio spontaneo su entrambi i form di L02, zoom effettivamente
mostrato a 200% nel menu browser e raggiungibilità sulle schermate di L03; annotare i ripristini.
Seguire [BROWSER-NATIVE-CHECKS.md](BROWSER-NATIVE-CHECKS.md). Senza questi riscontri **non segnare
completo UI.06.3** e non passare a UI.06.4/M9. La richiesta di osservazioni è pendente; un mancato
riscontro non equivale a PASS o autorizzazione a ridurre i criteri.
