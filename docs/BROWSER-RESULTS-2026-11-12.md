# Collaudo dei flussi essenziali — UI.06.2

Eseguito il **2026-11-12, 08:43–08:49 UTC**, sulla nuova interfaccia della fase A:
**22 flussi per browser, 44 controlli PASS, nessun difetto applicativo confermato**.
UI.06.2 completato; UI.06.3–UI.06.4 e l’accettazione della fase A restano aperti.
Il prossimo task comprende aggiornamento integrale della guida e L01–L08.

Questo registro integra [BROWSER-TESTS.md](BROWSER-TESTS.md), senza sostituire o modificare
le esecuzioni storiche A–L del 2026-11-09. BF01–BF22 identificano prove supplementari dei
flussi modificati: **non sono 44 casi A–L rieseguiti integralmente**, né una misura di coverage.
I riferimenti della tabella indicano l’area interessata; valgono soltanto i passi effettivamente descritti.

## Ambiente e metodo

- Origine unica `http://localhost:8000`; dati locali sintetici, HTTP/Laravel/PostgreSQL/Redis reali.
- Brave **157.1.99.56**, motore Chromium **157.0.8103.50**; Firefox **159.0 della distribuzione
  Playwright**. Firefox desktop 159.0.1 è installato, ma non è il binario eseguito in queste prove.
- Esecuzione Playwright **headless**, viewport 1440 × 1000, profili effimeri separati per owner,
  viewer e visitatore anonimo. Login dai form reali con le credenziali delle fixture documentate;
  nessuna sessione costruita nel database, nessuna risposta HTTP/socket o risultato check simulato.
- Playwright, Firefox e script di orchestrazione installati/eseguiti soltanto sotto
  `/private/tmp/signalbox-browser-20261112`; nessuna dipendenza o lockfile del progetto modificata.
- Tutti i servizi erano inizialmente arrestati. Avviati soltanto web/PostgreSQL/Redis, poi arrestati
  a fine prova. Schema già aggiornato, verificato con `migrate:status`; nessuna migration applicata.
  Scheduler, Horizon e Reverb non avviati: nessuna attestazione di nuovi check, realtime o consegna provider.
- `npm run build` e `npm run check` superati: ESLint, Prettier, TypeScript e cinque test degli strumenti
  di qualità. Suite PHP/DOM e CI remota/PHP 8.5 non rieseguite: nessun sorgente applicativo modificato;
  il gate precedente è documentato in [UI-ACCEPTANCE.md](UI-ACCEPTANCE.md).

## Prove riproducibili e risultati

Ogni riga è stata eseguita in **entrambi** i browser indicati sopra. Team Alpha = 3, Beta = 4;
monitor preesistenti 7/8, incidente Alpha 9, tutti sintetici. I valori sono fixture di questa esecuzione,
non identificativi da presumere validi in un’altra installazione.

| ID | Area guida | Passi e risultato osservato | Brave | Firefox |
|---|---|---|---|---|
| BF01 | A | Login owner dal form → `/account`, identità corretta | PASS | PASS |
| BF02 | A/I | Dashboard Alpha → select Team Beta → Switch team → dashboard Beta → ritorno Alpha; POST e URL coerenti | PASS | PASS |
| BF03 | B/G | Cerca PROVA-RUOLI-ALPHA, apri dettaglio, scegli 7 days/US, apri tabella equivalente, Back to monitors e reload: ricerca conservata | PASS | PASS |
| BF04 | B | Crea HTTP temporaneo: intervallo 10 rifiutato, poi 300 con URL loopback rifiutato; URL pubblico valido con query sintetica accettato e segreto redatto | PASS | PASS |
| BF05 | B | Modifica soltanto Name del temporaneo, Save changes → dettaglio; nome conservato al reload, segreto assente nell’HTML di modifica | PASS | PASS |
| BF06 | B | Pause monitor → Cancel senza effetto; Confirm pause, reload, Confirm resume: stati e comandi coerenti | PASS | PASS |
| BF07 | B | Delete monitor → Cancel conserva la risorsa; Confirm delete torna alla lista, vecchio URL 404 | PASS | PASS |
| BF08 | E | Lista incidenti resolved → #9, nota privata con stato Resolved, reload: una sola nota; ritorno alla lista conserva il filtro | PASS | PASS |
| BF09 | I | Monitor Beta 8 sotto Alpha restituisce 404; percorso corretto Beta restituisce il dettaglio | PASS | PASS |
| BF10 | I/K | Owner ordinario: directory piattaforma 404 e Horizon 403 | PASS | PASS |
| BF11 | I | Viewer legge lista/dettaglio/incidente; azioni assenti; ingressi diretti create/edit 403 | PASS | PASS |
| BF12 | I | Viewer estraneo a GiGa Team: dashboard, monitor, incidente 8, notifiche e gestione status page del team 2 restituiscono 404 | PASS | PASS |
| BF13 | A | Preferenza owner Alpha conservata, switch verso lo stesso team disabilitato; nessun evento pageerror durante i flussi ordinari | PASS | PASS |
| BF14 | D/F | Ricerca canale nasconde i non corrispondenti; link Notification deliveries e Maintenance aprono gli elenchi reali | PASS | PASS |
| BF15 | H | Ricerca/editor Ruoli Alpha; visitatore separato riceve 404 per la bozza e legge Stato Prova pubblicata senza layout privato | PASS | PASS |
| BF16 | K | Operatore temporaneo: conferma nativa → password inline; Cancel e password errata non applicano, password valida salva una volta; reload e rimozione ripristinano assenza override | PASS | PASS |
| BF17 | A/I | Membri/inviti/Plan and usage leggibili; dopo la password reale, sicurezza account e Authenticator caricati senza attivare 2FA o leggere codici | PASS | PASS |
| BF18 | G | Selezione novembre 2026, link Download PDF: file `signalbox-report-3-2026-11.pdf` con firma `%PDF-`, circa 24 KB | PASS | PASS |
| BF19 | D | Bozza nome canale conservata filtrando/nascondendo l’editor; salvataggio e reload, poi ripristino del nome originale | PASS | PASS |
| BF20 | H | Titolo della bozza Ruoli Alpha modificato, salvato e riletto; titolo originale ripristinato senza cambiare slug/pubblicazione/componenti/password | PASS | PASS |
| BF21 | K | Directory filtrata `q=synthetic-alpha` → dettaglio → Back to all teams conserva la ricerca e il contesto operatore separato | PASS | PASS |
| BF22 | A | Account menu → Sign out → home pubblica; successivo URL privato richiede login, nessun pageerror durante i flussi finali | PASS | PASS |

I timestamp per browser e i dettagli dei controlli finali sono in
[verification.json](browser-evidence/2026-11-12/UI06-2/verification.json). Il JSON documenta origine,
modalità, versioni, riepilogo e lista `checks` con browser/ID/ora/ruolo/esito/dettaglio; non contiene
cookie, password, payload, URL firmati o credenziali. I nomi delle note sono sintetici.

## Correzioni delle sonde, senza modifiche applicative

Durante la preparazione gli script cercavano la rimozione dal DOM di righe che il prodotto mantiene
correttamente montate ma nascoste, oppure testo esatto che comprende anche il badge Draft.
Le sonde sono state corrette per verificare la visibilità e il controllo Edit pertinente.
Altre attese errate cercavano Download PDF come pulsante anziché link, ignoravano il gate password
prima della sicurezza account, omettevano l’accettazione del dialogo nativo prima dell’override,
o attendevano `/login` immediatamente dopo logout, che porta invece alla home pubblica.
Le prove corrette sono state ripetute: questi errori di orchestrazione non sono difetti del prodotto.
Nessun FAIL iniziale viene trasformato in PASS senza ripetizione del relativo flusso.

## Ripristino verificato

- Monitor temporanei **31 (Brave)** e **32 (Firefox)** eliminati via UI. In Alpha/Beta restano
  soltanto i monitor originari 7/8, con nomi originali e ancora in pausa.
- Nome canale **Email admin prova** e titolo **Ruoli Alpha** ripristinati via UI; entrambe le status
  page Alpha restano bozze, canali sintetici non verificati/inattivi come prima. Stato Prova è stata
  soltanto letta anonimamente. Nessuna email, verifica canale o pubblicazione incidente richiesta.
- Owner sintetico inizialmente non operatore: assegnazione temporanea e revoca tramite
  `signalbox:platform-admin`, unico ingresso ammesso. Flag finale false; nessun override nei team
  3/4. Quattro eventi audit confermano un grant/clear per browser, senza duplicati.
- Preferenza owner ancora Alpha (3); preferenze admin/member/viewer ancora null. Credenziali,
  membership, trial e piani memorizzati non modificati. Incidente 9 ancora Resolved.
- Restano **due note private**, `UI06 Brave private note 2026-11-12` e
  `UI06 Firefox private note 2026-11-12`, una per browser, con `published_at = null`;
  conservati gli audit append-only dell’assegnazione/revoca operatore e dell’override.
  Non cancellare questi eventi per fingere un ripristino senza tracce.
- Browser effimeri chiusi, nessuna preferenza/profilo personale modificato; nessuno screenshot
  nel repository. Web/PostgreSQL/Redis nuovamente arrestati, volumi conservati. Nessun Git/gh eseguito.

## Limiti e prossimo passo

Questo smoke verifica interazioni e richieste reali della fase A, non ripete integralmente email,
inviti, cinque checker, escalation, quote di tutti i piani, 2FA, DNS/TLS pubblici o tutti i casi A–K.
Il PDF è scaricato e riconosciuto come PDF, senza attestazione di lettura visiva del documento.
L’assenza di `pageerror` non certifica l’assenza di ogni messaggio Console: Reverb è arrestato e
le connessioni live non sono oggetto del PASS. Nessuna prova di carico o comportamento produzione.

**UI.06.3** deve aggiornare schermate e guida mantenendo A–L ed eseguire L01–L08: tastiera/focus,
VoiceOver reale, zoom browser nativo 200%, 390 px, rete lenta/offline, doppio invio, cronologia e
Console, confronto fra browser. I controlli headless di questa sessione non assegnano nuovi PASS L.
UI.06.4 deve poi confermare esplicitamente l’uscita fase A; **M9 non è avviato**.
