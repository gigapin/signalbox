# Accettazione fase A UI e handoff — 2026-11-12

**Fase A accettata; UI.06.4 e UI.06 completati.** Il prossimo task pronto è **M9.01**, non ancora
avviato. Il macro UI rimane aperto per billing/API e pagine pubbliche successive; UI.07 conserva
la decisione su nome/logo. Stato: **23/27 sotto-task completati, 4 aperti**, oltre alla decisione
UI.07 distinta dal conteggio dei sotto-task.

Questa accettazione riguarda UI.01–UI.03, UI.04.1–UI.04.4 e UI.06: percorsi implementati,
ruoli/tenancy, stati/azioni/errori e collaudo L della fase A. Non certifica produzione, carico,
nuove consegne provider, nuovi check o la futura integrazione Stripe. Non riduce la specifica:
M3–M5, domini personalizzati, PDF, portale clienti e M9–M12 rimangono nel perimetro.

## Criteri ed evidenza

| Criterio di uscita | Evidenza verificabile | Esito e limite |
|---|---|---|
| Navigazione, dashboard e dettaglio con dati reali | [Audit automatico](UI-ACCEPTANCE.md), [flussi essenziali](BROWSER-RESULTS-2026-11-12.md), mappa in [BROWSER-TESTS](BROWSER-TESTS.md) | Soddisfatto per la fase A; grafici/ritorni, periodi/regione e dati mancanti reali; non una misura di latenza o carico |
| Mutazioni, dinieghi e isolamento | Suite DOM/PHP/protocollo nella matrice e 44 controlli supplementari Brave/Firefox | Soddisfatto nei percorsi documentati; nessun PASS dedotto per l’intero vecchio catalogo A–K |
| Realtime e assenza di regressioni di lifecycle | Test UI.03.8/UI.06.1, [REALTIME-ACCEPTANCE](REALTIME-ACCEPTANCE.md) | Copertura automatica esistente conservata; G08–G10 live rimane facoltativo e senza nuovo PASS |
| Tastiera, focus, reflow e interazioni browser | [Registro L](BROWSER-ACCESSIBILITY-2026-11-12.md), 64 sonde iniziali | L01/L04–L08 soddisfatti nei perimetri dichiarati; errori Console attribuiti e limiti del secondo browser espliciti |
| VoiceOver | Tre riscontri utente, quattro sotto-controlli monitor/canale × Brave/Firefox nel JSON UI06-3 | L02 soddisfatto secondo l’ascolto riferito dall’utente; nessuna trascrizione esatta o audio osservato dall’agente inventati |
| Zoom nativo 200% | 22 controlli, API zoom Brave e fullZoom/indicatore 200% Firefox desktop 159.0.1 | L03 soddisfatto, oltre alle 22 misure iniziali a 390 px; fattori ripristinati a 1, CSS zoom invariato |
| Gate applicativi pertinenti | Gate UI.06.1: 3315 backend / 22956 asserzioni, 467 DOM, 5 qualità, formatter/analisi/build; check frontend UI.06.2–UI.06.3 | Gate storici registrati, non rieseguiti né presentati come nuovi risultati di UI.06.4; nessun sorgente applicativo cambiato in questa chiusura |
| Ripristini, piani e motivi | Audit PostgreSQL READ ONLY sotto, stato desktop/runtime e pulizia profili | 15 controlli positivi; nessuna scrittura di dominio nell’audit; VoiceOver ora non in esecuzione; tutti i servizi arrestati |

I conteggi appartengono a prove diverse e talvolta sovrapposte: **non si sommano come casi unici
A–L o percentuali di coverage**. Le versioni browser installate e quelle eseguite via Playwright/
Marionette sono distinte nei registri; la versione della finestra usata dall’utente non è stata
comunicata direttamente. I suoi riscontri restano attribuiti alla procedura richiesta.

## Osservazioni valutate e decisioni

**Nessun difetto funzionale bloccante rimane nei percorsi collaudati.** Il 404 di `/favicon.ico`
in Brave è classificato come risorsa cosmetica mancante: non interrompe navigazione, form,
autorizzazioni o codice JavaScript. Resta esplicitamente da trattare con **UI.07**, quando saranno
definiti nome/logo/favicon. Non si introduce un nuovo segno grafico per silenziare il messaggio;
UI.07 era già distinto e non bloccante per la fase A. Il difetto non viene cancellato dai registri.

Le connessioni WebSocket rifiutate dipendono dal listener Reverb intenzionalmente fermo nelle prove
UI; non attestano un guasto del dominio né una nuova prova live riuscita. Le prime sonde errate o
il proxy temporaneo non affidabile sono conservati come limiti del metodo nei registri, esclusi
come prova di accettazione. Nessuna eccezione JS applicativa inattesa confermata resta aperta.

## Audit finale dei dati sintetici

Eseguito alle **11:40:20 UTC** sul database locale esistente, con `SET TRANSACTION READ ONLY`.
Avviati solo PostgreSQL/Redis; un container web one-off esegue PHP CLI e viene rimosso, senza server
HTTP, scheduler, worker o migration. Proiezioni esportate limitate agli ID delle fixture; password, cookie,
configurazioni dei canali, segreti e token non sono serializzati né esportati.

**15 verifiche superate**, comprendenti:

- Identità 15–18 non operatori; preferenza owner ancora Alpha 3, altre preferenze null; otto membership
  originali owner/admin/member/viewer nei team 3/4.
- Monitor originari 7/8, nomi invariati, pausa attiva, intervallo 300 s e soglia 2; temporanei 31/32 assenti.
- Nessun override Alpha/Beta; piano memorizzato Free, piano effettivo Pro da trial fino al
  **2026-11-16 15:43:40 UTC**, limite 50 monitor, minimo 60 s, 3 status page. Snapshot temporale:
  dopo la scadenza vanno ricalcolati i limiti, non modificati i trial per conservare il test.
- Canali 4/5 con nomi originali, ancora non verificati/inattivi e senza quiet hours; nessun canale
  creato dai quattro invii invalidi con Name vuoto.
- Bozze Ruoli Alpha/Ruoli Admin inalterate, senza password; pagine temporanee L08 assenti. Pagina
  pubblica Stato Prova invariata: pubblicata, tema light/amber, senza password.
- Nove note sintetiche uniche **44–52**, tutte private e non pubblicate sull’incidente 9 ancora
  Resolved. Quattro audit grant/clear dell’override, due per browser, con i motivi di ripristino
  originali; nessun salvataggio duplicato. Le note e gli audit restano intenzionalmente conservati.

L’audit conferma gli invarianti selezionati, non confronta un dump completo byte per byte o una
baseline di tutti i dati del progetto. I vincoli di cancellazione subscriber/outbox e i limiti delle
prove email sono documentati nel registro L; nessuna consegna reale attestata da un’assenza.

## Stato finale e adozione

- **VoiceOver non è in esecuzione**, verificato due volte con lettura AppleScript del solo stato
  dell’applicazione; nessuna autorizzazione accessibilità o preferenza personale modificata dall’agente.
  I fattori zoom dei profili temporanei erano già stati ripristinati da 2 a 1 in UI.06.3.
- Nessun processo usa i quattro profili browser temporanei noti; quei quattro profili sono eliminati.
  Script e tooling di prova restano soltanto in `/private/tmp`. Nessuno screenshot nel repository.
- Tutti i servizi Compose sono nuovamente **arrestati**, inclusi PostgreSQL/Redis usati per l’audit;
  volumi conservati. Nessun nuovo worker/processo permanente, migration, chiave o modifica `.env`.
- La configurazione locale osservata resta **monitor-scheduling.enabled=true** e broadcasting **reverb**:
  i processi fermi non eseguono controlli o consegne. Un futuro avvio di scheduler/worker può riprendere
  il lavoro già configurato; questa chiusura non lo avvia e non cambia tacitamente i flag.
- Nessuna modifica applicativa da adottare: documenti/evidenza soltanto, nessuna nuova installazione
  Composer/npm o build richiesta da UI.06.4. Nessun Git/gh eseguito o stato di sincronizzazione attestato.

## Handoff operativo

Prossimo **M9.01**: Cashier/Stripe test, piani, trial, subscription e subscription_items secondo la
specifica e le dipendenze già bloccate. Prima del lavoro leggere TASKS, DEPENDENCIES, BOOTSTRAP e
la matrice piani in ARCHITECTURE; verificare i riferimenti ufficiali pertinenti alla configurazione
billing. Non usare chiavi live, non alterare i trial sintetici per far passare i test e non avviare
scheduler/worker senza considerare i flag e i dati preesistenti. Nessuna configurazione Stripe è
stata effettuata da questa chiusura.

UI.04.5 e UI.05 restano successivi alle dipendenze M9–M10; UI.07 rimane bloccato sulla decisione
nome/logo, con favicon incluso. Il portale clienti, i domini personalizzati e il PDF non sono ridotti.

## Evidenza e verifiche di questa chiusura

[verification.json](browser-evidence/2026-11-12/UI06-4/verification.json) documenta fase/scope, istante
finale, fixture_audit (ora, READ ONLY, controlli, piani, note/audit e due flag runtime pubblicabili),
desktop, runtime, profile_cleanup, findings, retained_scope, verification_boundary, next_task e
assenza operazioni Git. È uno snapshot di verifica, non un manifest applicativo o un certificato remoto.

Verificati i collegamenti locali dei documenti modificati, la validità/coerenza dei JSON, i 169 ID
A–L e la tabella esiti storica invariati, gli stati delle checklist e la preservazione del perimetro.
**Non ripetuti:** formatter/analisi/suite PHP e DOM o build (nessun sorgente modificato), CI remota e
PHP 8.5, nuovi check/realtime/provider reali o carico. I gate precedenti sono citati con data/provenienza;
nessun risultato o metriche nuovi vengono inventati.
