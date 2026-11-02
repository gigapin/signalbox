# Prove manuali nel browser (fino a M8)

Guida passo per passo per provare l'applicazione com'è dopo M8, prima di iniziare M9. Non sostituisce i test automatici: controlla ciò che loro non vedono, cioè browser reale, email reali, aspetto delle pagine e flussi completi.

**Stato: nessun caso eseguito.** Nessun esito di questo documento è stato verificato dall'agente; i comandi di preparazione modificano l'ambiente e sono lasciati a te.

## Come usare questo documento

- Procedi in ordine: i blocchi successivi riusano i dati creati prima. Durata indicativa: 3–4 ore, comprese le attese.
- Ogni caso ha un codice. Annota nella [tabella finale](#registro-degli-esiti) `PASS`, `FAIL`, `BLOCCATO` o `NON ESEGUITO`.
- Esegui i comandi dalla cartella del progetto:

  ```sh
  cd ~/Projects/signalbox
  ```

- Sostituisci `{TEAM}`, `{MONITOR}` e `{SLUG}` con i valori reali.
- L'interfaccia è in inglese: i nomi di pulsanti e campi sono riportati esattamente come li vedi.
- Non giudicare qui la grafica: è provvisoria e verrà rifatta con i task UI. Segna quello che non ti piace a parte, nella [sezione dedicata](#note-sulla-grafica).

**Se qualcosa non va**, annota URL, account, cosa ti aspettavi, cosa è successo ed eventuali errori della Console del browser (F12). Non incollare mai token, password o codici. Sono da correggere prima di M9 i problemi di sicurezza, permessi, dati di altri team, errori 500 e perdita di dati.

---

## 1. Preparazione

### 1.1 Attiva i controlli e aggiorna il codice

Apri `.env` e aggiungi in fondo:

```sh
MONITOR_SCHEDULING_ENABLED=true
```

Poi ricompila gli asset e riavvia i processi:

```sh
docker compose run --rm node npm run build
docker compose up -d --no-deps --force-recreate web queue scheduler
```

Controlla che sia tutto attivo:

```sh
docker compose ps
docker compose exec web php artisan horizon:status
docker compose exec web php artisan config:show monitor-scheduling.enabled
docker compose exec web php artisan migrate:status | grep -c Pending
```

**Atteso:** `web`, `queue`, `scheduler`, `postgres` e `redis` in stato `running` (`reverb` può restare fermo); `Horizon is running`; valore `true`; conteggio `0`.

> Da qui in poi l'applicazione fa richieste reali verso i siti che configuri. A fine sessione segui la [pulizia](#pulizia-finale).

### 1.2 Tieni aperte le finestre di controllo

In un terminale separato lascia scorrere i log. Serve per capire cosa succede quando un caso non torna:

```sh
docker compose logs -f --tail=20 queue
```

In un altro, gli errori dell'applicazione:

```sh
tail -f storage/logs/laravel.log
```

### 1.3 Email

Le email vanno nella sandbox Mailtrap configurata in `.env`, non alle caselle reali. Accedi a <https://mailtrap.io>, apri la tua **Sandbox** e tienila aperta in una scheda: lì trovi inviti, codici e conferme, qualunque sia il destinatario.

Se non arriva nulla entro un minuto, controlla il terminale del punto 1.2 e le credenziali `MAIL_*` in `.env`.

### 1.4 Account di prova

Esistono già due account verificati, `tester@email.local` e il tuo. Crea anche gli account con i quattro ruoli, senza toccare i dati esistenti:

```sh
docker compose exec web php artisan db:seed --class=IdentitySeeder
docker compose exec postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "UPDATE users SET email_verified_at = now() WHERE email LIKE '"'"'%@signalbox.test'"'"' AND email_verified_at IS NULL;"'
```

Il primo comando crea `owner@`, `admin@`, `member@` e `viewer@signalbox.test`, con password `password`, e i team Synthetic Alpha e Synthetic Beta. I quattro account appartengono a **entrambi** i team. Il secondo comando li segna come verificati, perché il seeder non lo fa.

Per vedere gli identificativi dei team:

```sh
docker compose exec postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT id, name, plan, trial_ends_at FROM teams ORDER BY id;"'
```

| Chi | Account | Uso |
|---|---|---|
| Tu | il tuo account, team **GiGa Team** | Flussi principali dei blocchi B–H |
| Ruoli | `owner@`, `admin@`, `member@`, `viewer@signalbox.test` | Permessi, blocco I |
| Estraneo | `tester@email.local`, Tester Team | Isolamento tra team, blocco I |

**Usa profili separati del browser** per account diversi, oppure una finestra privata: nello stesso profilo le sessioni si sovrascrivono.

### 1.5 Attenzione ai trial

Ogni team nasce con un trial Pro che scade presto:

| Team | Fine trial (ora italiana) | Dopo la scadenza |
|---|---|---|
| Tester Team | 3 novembre 2026, 16:58 | Limiti Free |
| GiGa Team | 6 novembre 2026, 13:33 | Limiti Free |

I limiti sono: **Free** 5 monitor, intervallo minimo 300 secondi, 1 status page; **Pro** 50 monitor, 60 secondi, 3 status page.

Se fai le prove dopo la scadenza dovrai usare intervalli di 300 secondi, e un incidente richiederà molto più tempo. In quel caso imposta prima un override con il [blocco K](#blocco-k--pannello-amministratore).

### 1.6 Destinazioni dei monitor

La protezione SSRF rifiuta localhost e reti private, quindi servono siti pubblici. Queste destinazioni sono sintetiche e non richiedono credenziali:

| Scopo | Valore |
|---|---|
| HTTP che risponde bene | `https://httpbin.org/status/200` |
| HTTP guasto | `https://httpbin.org/status/503` |
| Keyword | `https://example.com`, parola `Example Domain` |
| TLS | host `example.com`, porta 443 |
| DNS | `one.one.one.one`, tipo A, valori `1.1.1.1` e `1.0.0.1` |

`httpbin.org` è un servizio di terzi e a volte è lento: uno stato `down` isolato con timeout può dipendere da lui. Se succede, ripeti o alza il timeout.

---

## Blocco A — Accesso e account

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| A01 | Finestra privata: apri `http://127.0.0.1:8000/` | Home con Sign in e il testo provvisorio "The application is being built" (noto, UI.05) |
| A02 | Finestra privata: apri `http://127.0.0.1:8000/teams` | Rimanda al login, nessun dato mostrato |
| A03 | Accedi con un account errato, poi con quello giusto | Prima un errore comprensibile, poi accesso |
| A04 | Apri `/register` e crea un account nuovo con un'email finta | Pagina di richiesta verifica; email in Mailtrap |
| A05 | Prima di verificare, apri `/teams` con quell'account | Sei riportato alla verifica |
| A06 | Apri il link dell'email | Account verificato, accesso alle pagine |
| A07 | Esci. Su `/forgot-password` chiedi il reset e segui il link in Mailtrap | Password cambiata; la vecchia non funziona più |
| A08 | Vai su `/account/security`, premi **Enable two-factor authentication** (può chiederti di confermare la password), poi **Show authenticator setup**. Inquadra il QR con un'app authenticator, inserisci il codice in **Authentication code** e premi **Confirm authenticator** | 2FA attiva e codici di recupero disponibili con **Show recovery codes** |
| A09 | Esci e rientra con quell'account | Viene chiesto il codice; senza il codice giusto non entri |
| A10 | Rientra usando un codice di recupero, poi premi **Disable two-factor authentication** | Accesso riuscito e 2FA disattivata. Il codice usato non vale una seconda volta |

Fai A08–A10 con l'account creato in A04, non con il tuo, a meno che ti serva per il blocco K.

---

## Blocco B — Monitor

Accedi con il tuo account. Apri `/teams` e scegli GiGa Team: la pagina è `/teams/{TEAM}`. Annota il numero. Vai su **Monitors**, poi **New monitor**.

### B1. Creazione

Crea questi cinque monitor. Dove non indicato: **Timeout** 5000, **Regions** solo EU.

| Caso | Name | Type | Interval | Altri campi | Failures before alerting |
|---|---|---|---|---|---|
| B01 | `PROVA-HTTP` | HTTP | 60 | **URL** `https://httpbin.org/status/200`, **Method** GET, **Expected status** 200 | 2 |
| B02 | `PROVA-KEYWORD` | Keyword | 60 | **URL** `https://example.com`, **Keyword** `Example Domain`, **Keyword must be** `present` | 2 |
| B03 | `PROVA-TLS` | TLS | 300 | **Host** `example.com`, **Port** 443, **Expiry warning (days)** 7 | 3 |
| B04 | `PROVA-DNS` | DNS | 300 | **Query name** `one.one.one.one`, **Record type** A, **Expected values** `1.1.1.1` e, con **Add value**, `1.0.0.1`. **Resolver** vuoto | 3 |
| B05 | `PROVA-HEARTBEAT` | Heartbeat | 60 | **Grace period (seconds)** 30 | 2 |

**Atteso per ognuno:** compaiono solo i campi del tipo scelto, poi si arriva al dettaglio con messaggio di conferma. Lo stato iniziale è `unknown`.

### B2. Stato reale

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| B06 | Attendi 2–3 minuti e ricarica la lista **Monitors** | `PROVA-HTTP` e `PROVA-KEYWORD` diventano `up`, con **Last check** aggiornato |
| B07 | Attendi 6–7 minuti e ricarica | Anche `PROVA-TLS` e `PROVA-DNS` diventano `up` |

Se resta tutto `unknown`, controlla la sezione [Se qualcosa non parte](#se-qualcosa-non-parte).

### B3. Validazione e sicurezza

Da **New monitor**, senza salvare nulla di valido:

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| B08 | Invia con **Name** vuoto | Errore vicino al campo, nessun monitor creato |
| B09 | **Interval** 10 | Rifiutato |
| B10 | Tipo HTTP con URL `http://127.0.0.1/` | Rifiutato con un messaggio che non rivela dettagli della rete |
| B11 | URL `http://192.168.1.1/` | Rifiutato |
| B12 | URL `https://utente:segreto@example.com/` | Rifiutato |
| B13 | Tipo TLS senza host; tipo DNS senza valori attesi | Rifiutati |

### B4. Segreti

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| B14 | Crea `PROVA-SEGRETI`: HTTP, URL `https://httpbin.org/status/200?api_key=segreto-prova`, con **Add header** `Authorization` = `Bearer segreto-prova` | Monitor creato |
| B15 | Nel dettaglio e in **Edit monitor** cerca `segreto-prova`. Premi Ctrl/Cmd+U e cerca anche nel sorgente | Il testo non compare: il valore della query è nascosto, dell'header si vede solo il nome |
| B16 | In **Edit monitor** cambia solo il nome e salva. Dopo un minuto il monitor è ancora `up` | URL e header non sono andati persi |
| B17 | Elimina il monitor con **Delete monitor**: prima annulla, poi conferma | Annullando non cambia nulla; confermando torni alla lista, e il vecchio URL dà 404 |

### B5. Lista e pausa

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| B18 | Cerca `PROVA-`, filtra per tipo e stato, poi **Clear filters** | Risultati coerenti, poi lista completa |
| B19 | Cerca `inesistente` | Messaggio "No monitors match these filters on this page." |
| B20 | Aggiungi `?type=nonexistent` all'URL della lista | Avviso sul filtro rifiutato, pagina utilizzabile |
| B21 | In `PROVA-TLS` premi **Pause monitor**, poi **Resume monitor** | Lo stato operativo passa a pausa e torna attivo, senza errori |

---

## Blocco C — Heartbeat

Apri `PROVA-HEARTBEAT`.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| C01 | Premi **Generate credential** | Compare il comando, con l'avviso di salvarlo subito |
| C02 | Premi **Copy heartbeat command** e incollalo in un terminale | Il comando termina senza errori (risposta 204, nessun testo) |
| C03 | Ricarica la pagina | La credenziale non è più visibile; l'ultima ricezione è aggiornata |
| C04 | Apri F12 → Application e cerca il token in Local Storage e Session Storage | Non c'è |
| C05 | Tieni il ping attivo per i prossimi passi con questo ciclo, sostituendo la riga con il comando copiato | Il monitor diventa `up` |

```sh
while true; do curl --fail --request POST --header 'Authorization: Bearer IL_TUO_TOKEN' http://127.0.0.1:8000/heartbeats; sleep 45; done
```

Verifica i rifiuti in un altro terminale:

```sh
curl -i -X POST http://127.0.0.1:8000/heartbeats
curl -i -X POST -H 'Authorization: Bearer token-inventato' http://127.0.0.1:8000/heartbeats
curl -i http://127.0.0.1:8000/heartbeats
```

| Caso | Risultato atteso |
|---|---|
| C06 | I primi due comandi rispondono **404** senza dettagli sul monitor; il terzo **405** |

Lascia il ciclo attivo: lo fermerai nel blocco E per provocare un incidente. La rotazione e la revoca sono nel blocco E.

---

## Blocco D — Notifiche

Vai su **Notifications** (`/teams/{TEAM}/notifications`).

### D1. Canali

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| D01 | In **Add a channel**: **Channel type** Email, **Name** `Email prova`, **Email address** `prova@example.test`. Premi **Add channel** | Canale creato, inattivo e non verificato |
| D02 | Premi **Send verification code** e apri l'email in Mailtrap | Email arrivata con un codice di sei cifre |
| D03 | Inserisci un codice sbagliato in **Verification code** e premi **Verify** | Rifiutato, il canale resta non verificato |
| D04 | Inserisci il codice giusto | Canale verificato e attivo |
| D05 | Apri <https://webhook.site> e copia l'URL che ti assegna. Crea un canale di tipo Webhook con **Endpoint URL** uguale a quello. Invia e conferma il codice, che trovi nel corpo della richiesta su webhook.site | Canale attivo. La richiesta ha gli header `X-Signalbox-Id`, `X-Signalbox-Timestamp` e `X-Signalbox-Signature` |
| D06 | Premi **Create new signing secret**, poi **Hide secret**, poi ricarica | Il segreto si vede una volta sola |
| D07 | Crea un canale SMS con un numero finto (`+393331234567`) | Indicato come stub; non può essere verificato né attivato |
| D08 | Crea un canale Webhook con URL `http://127.0.0.1/` | Rifiutato: è ammesso solo HTTPS |
| D09 | Facoltativo. Crea un canale Slack o Telegram, se hai un webhook o un bot di prova | Codice ricevuto sul canale, attivazione riuscita |

Webhook.site è un servizio di terzi: non inviargli dati veri. In queste prove riceve solo dati sintetici.

### D2. Policy di escalation

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| D10 | In **Add a policy**: **Policy name** `Policy prova`, attiva **Team default**. Primo step: minuti `0`, canale `Email prova`. Con **Add step** aggiungi un secondo step: minuti `5`, canale Webhook. Premi **Add policy** | Policy salvata come predefinita |
| D11 | Apri `PROVA-HTTP`, sezione **Notifications** | Mostra come policy effettiva `Policy prova` |

### D3. Quiet hours

Le prove sulle quiet hours sono nel [blocco F](#blocco-f--manutenzioni-e-quiet-hours), dopo aver visto una notifica normale.

---

## Blocco E — Incidenti

### E1. Guasto reale

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| E01 | In `PROVA-HTTP` apri **Edit monitor**, seleziona **Replace URL** e inserisci `https://httpbin.org/status/503`. Salva e attendi 2–3 minuti, ricaricando | Monitor `down`, e un nuovo incidente in **Recent incidents** e in **Incidents** |
| E02 | Controlla Mailtrap | Email di apertura incidente a `prova@example.test` |
| E03 | Apri l'incidente | Timeline, stato, severità e tabella **Notifications sent** con la consegna |
| E04 | Dopo 5 minuti ricontrolla webhook.site e la tabella | Arriva il secondo step (Escalation) |

### E2. Gestione

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| E05 | Premi **Take responsibility** | Compari come responsabile |
| E06 | In **Add a timeline update** scegli uno stato, scrivi `Nota privata di prova` e premi **Add update** | Voce con etichetta **Private** |
| E07 | Scrivi `Stiamo verificando il problema` e premi **Add update**, poi **Publish update** su quella voce | Voce pubblicata |
| E08 | Cambia la severità con **Save severity** e scrivi in **Declared cause** `Causa privata di prova`, poi **Save cause** | Valori salvati |
| E09 | Ricarica e controlla che tutto sia rimasto | Dati presenti |

### E3. Risoluzione e riapertura

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| E10 | Rimetti l'URL `https://httpbin.org/status/200` e attendi 1–2 minuti | Monitor `up`, incidente risolto automaticamente |
| E11 | Controlla Mailtrap | Riepilogo di risoluzione, un solo messaggio per canale |
| E12 | Conta le email ricevute per questo incidente | Nessun duplicato |
| E13 | Riporta l'URL a `/status/503`, attendi l'incidente, poi torna a `/status/200` | Si comporta come E01–E10, senza incidenti duplicati aperti insieme. Se avviene poco dopo il precedente, può riaprire lo stesso incidente anziché crearne uno nuovo: annota quale dei due vedi |

### E4. Heartbeat mancato

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| E14 | Ferma il ciclo del blocco C con Ctrl+C e attendi 3–4 minuti | `PROVA-HEARTBEAT` passa a `down` e si apre un incidente |
| E15 | Riavvia il ciclo e attendi 2 minuti | Torna `up` e l'incidente si risolve |
| E16 | Premi **Rotate credential** e conferma. Riprova il comando vecchio, poi quello nuovo | Il vecchio risponde 404, il nuovo funziona (senza testo). Riavvia il ciclo con il token nuovo |
| E17 | Premi **Revoke credential** e conferma. Riprova il comando | 404 |

Dopo E17 ferma il ciclo e metti in pausa il monitor, così non genera altri incidenti.

---

## Blocco F — Manutenzioni e quiet hours

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| F01 | In `PROVA-HTTP`, sezione **Maintenance**: lascia vuoto l'inizio, imposta la fine tra 15 minuti, **Reason** `Manutenzione privata di prova`. Salva | Finestra in corso |
| F02 | Durante la finestra imposta l'URL `/status/503` e attendi 3 minuti | Stato `down`, ma **nessun incidente** aperto e nessuna email |
| F03 | Premi **End now** e attendi 2–3 minuti | Si apre l'incidente, ora che la manutenzione è finita |
| F04 | Rimetti l'URL `/status/200` e attendi la risoluzione | Monitor `up`, incidente risolto |
| F05 | Crea una finestra futura e premi **Edit window** per cambiarla, poi **Delete window** | Modificabile ed eliminabile prima dell'inizio |
| F06 | Prova a inserire un inizio nel passato | Rifiutato |
| F07 | Nel canale `Email prova` premi **Configure quiet hours**, attiva **Enable daily quiet hours** con un intervallo che comprende l'ora attuale e il tuo fuso in **Quiet hours timezone**. Premi **Save quiet hours** | Salvato |
| F08 | Provoca un incidente come in E01 e lascialo aperto almeno 6 minuti | Nessuna email durante le quiet hours; il webhook arriva normalmente al suo step, dopo 5 minuti |
| F09 | Controlla la tabella **Notifications sent** dell'incidente, poi rimetti `/status/200` | Lo stato spiega la consegna rinviata o soppressa. Annota cosa vedi |
| F10 | Disattiva le quiet hours e salva | Canale di nuovo normale |

---

## Blocco G — Dashboard e report

Apri **Dashboard** (`/teams/{TEAM}/dashboard`).

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| G01 | Controlla i conteggi | Corrispondono alla lista monitor: up, down, unknown, in pausa |
| G02 | Con un guasto attivo controlla **Open incidents** | L'incidente è presente; il link porta al dettaglio |
| G03 | In **Monitors** seleziona un monitor per i grafici | Grafico **Last 24 hours · Hourly buckets · UTC** con i dati di quel monitor |
| G04 | Apri la tabella dei dati sotto il grafico | Valori coerenti con il grafico |
| G05 | Controlla **Observed uptime**, **Data coverage**, **SLA**, **Time up**, **Time down**, **Time unknown** e **Time excluded** | Valori plausibili. Con dati parziali lo **SLA** può risultare non disponibile: è corretto, non è un errore |
| G06 | In **Monthly report** scegli il mese corrente e premi **Download PDF** | Scarica `signalbox-report-…pdf`, leggibile, con i monitor del team |
| G07 | Controlla nel PDF che non compaiano URL o dati di altri team | Nessun dato estraneo |

### Aggiornamenti in tempo reale (facoltativo)

Sono spenti di default. Per attivarli, in `.env` imposta `BROADCAST_CONNECTION=reverb` e avvia:

```sh
docker compose --profile realtime up -d reverb
docker compose up -d --no-deps --force-recreate web queue scheduler
```

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| G08 | Apri la dashboard e provoca un guasto come in E01, senza ricaricare | La pagina si aggiorna da sola |
| G09 | In F12 → Network → WS controlla la connessione | Canale `private-team.{TEAM}` e nessuna richiesta periodica di polling |
| G10 | Ferma Reverb con `docker compose stop reverb`, attendi, poi riavvialo | La pagina resta usabile, si riconnette e recupera i dati |

---

## Blocco H — Status page

Vai su **Status pages** (`/teams/{TEAM}/status-pages`).

### H1. Creazione e bozza

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H01 | In **Add a status page**: **Title** `Stato Prova`, **Public address** `stato-prova`, **Color scheme** dark, un **Accent**, lascia **non** pubblicata. Aggiungi con **Add component** un componente **Public name** `API pubblica` con `PROVA-HTTP` e un altro `Sito` con `PROVA-KEYWORD`. Premi **Add status page** | Pagina salvata come **Draft** |
| H02 | Finestra privata: apri `http://127.0.0.1:8000/status/stato-prova` | 404, come un indirizzo inesistente |
| H03 | Pubblica la pagina e premi **Save page**, poi ricarica nella finestra privata | La pagina si apre |

### H2. Cosa vede il visitatore

Nella finestra privata:

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H04 | Guarda la pagina | Titolo, stato complessivo, i due componenti col loro stato, la nota "History days are whole UTC days" |
| H05 | Guarda lo storico | "No uptime data in the last 90 days" o giorni senza dati: oggi non è conteggiato e i rollup arrivano a giorno chiuso. Non deve mostrare il 100% senza dati |
| H06 | In **Published incidents** | C'è `Stiamo verificando il problema`, senza `Nota privata di prova` |
| H07 | Ctrl/Cmd+U e cerca: `PROVA-HTTP`, `httpbin`, `Nota privata`, `Causa privata`, `Manutenzione privata`, il nome del team, la tua email, `team_id`, `monitor_id` | Nessun risultato |
| H08 | Sempre nel sorgente cerca il titolo e `Stiamo verificando` | Presenti, come controllo che la ricerca funzioni |
| H09 | Disattiva JavaScript nel browser e ricarica, poi riattivalo | Riepilogo leggibile con titolo, stati e voci pubblicate |
| H10 | Prova **Color scheme** light e system, e tutti gli **Accent** | Testo sempre leggibile |
| H11 | Riduci la finestra a larghezza da telefono (F12 → icona dispositivo) | Contenuti leggibili, nessun blocco che esce dallo schermo |

Controlla gli header da terminale:

```sh
curl -sI http://127.0.0.1:8000/status/stato-prova | grep -iE 'cache-control|x-robots-tag|^link'
```

| Caso | Risultato atteso |
|---|---|
| H12 | `Cache-Control` con `no-store` e `private`, `X-Robots-Tag: index, follow` e un `Link` con `rel="canonical"` |

### H3. Aggiornamento e cache

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H13 | Con un monitor della pagina provoca un incidente e ricarica la pagina pubblica | Stato `Outage` o `Partial outage`, al più tardi entro 60 secondi |
| H14 | Pubblica una voce dall'incidente e ricarica | Compare subito |
| H15 | Cambia titolo e **Save page**, ricarica | Nuovo titolo subito |
| H16 | Cambia **Public address** in `stato-prova-2` | Il vecchio indirizzo dà 404, il nuovo funziona. Poi rimettilo a `stato-prova` |
| H17 | Risolvi l'incidente e ricarica | Stato di nuovo operativo |

### H4. Password

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H18 | Imposta una password di 8+ caratteri, salva, ricarica nella finestra privata | "This status page is protected", senza titolo o componenti |
| H19 | Ctrl/Cmd+U | Nessun titolo, nome di componente o voce; `noindex` nei meta |
| H20 | Inserisci una password sbagliata e premi **Open page** | Errore |
| H21 | Sbaglia altre volte, fino a 6 tentativi in un minuto | Blocco temporaneo (errore 429 o messaggio equivalente). Annota come si presenta |
| H22 | Dopo un minuto inserisci quella giusta | Pagina visibile |
| H23 | Cambia la password dal pannello e ricarica nella finestra privata | Richiesta di nuovo la password |
| H24 | Con il tuo account, anche se sei owner, apri la pagina pubblica | Chiede la password anche a te |
| H25 | Rimettila in bozza con la sessione sbloccata, poi ricarica | 404 immediato |
| H26 | Pubblicala di nuovo e premi **Remove the password** | Aperta a tutti |

### H5. Iscrizione email

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H27 | Nella finestra privata, in **Email updates**, inserisci `lettore@example.test` e premi **Subscribe to updates** | Messaggio generico di conferma |
| H28 | Attendi fino a un minuto e apri l'email in Mailtrap | Email di conferma senza titolo della pagina né dati del team |
| H29 | Apri il link di conferma | Pagina con richiesta di conferma, **non ancora confermato**: serve premere il pulsante, così un antivirus che apre il link non conferma nulla |
| H30 | Pubblica una voce di incidente **prima** di confermare | Nessuna email a `lettore@` |
| H31 | Premi il pulsante di conferma | "Your email is confirmed" |
| H32 | Pubblica una nuova voce di incidente | Email con titolo, componente e testo pubblicato; link per disiscriversi. Nessun nome di monitor, autore o nota privata |
| H33 | Iscrivi di nuovo `lettore@example.test` | Stesso messaggio, nessuna nuova email: non si capisce se l'indirizzo esiste già |
| H34 | Aggiungi una voce privata senza pubblicarla | Nessuna email |
| H35 | Apri il link di disiscrizione e conferma con il pulsante | "You are unsubscribed" |
| H36 | Pubblica un'altra voce | Nessuna email a `lettore@` |
| H37 | Modifica il link di conferma cambiando un carattere | Errore, nessuna modifica |

Il limite è di 5 richieste di iscrizione al minuto per IP: se lo raggiungi, attendi un minuto.

### H6. Dominio personalizzato

In locale puoi provare la schermata e il rifiuto. La verifica completa richiede DNS pubblico. TLS e certificati arrivano con M12.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H38 | In **Custom domain**, **Hostname** `status.esempio-prova.com`, premi **Save hostname** | Mostra **Record name**, **Record value** e "Ownership not verified" |
| H39 | Prova `https://x.com`, `127.0.0.1`, `*.example.com`, `localhost` | Tutti rifiutati |
| H40 | Premi **Verify DNS ownership** | Rifiutato, perché il record non esiste. Il messaggio non dice se il nome è usato da altri |

Prova da terminale che un dominio non verificato non serva nulla:

```sh
curl -i -H 'Host: status.esempio-prova.com' http://127.0.0.1:8000/status/stato-prova
curl -s -o /dev/null -w '%{http_code}\n' 'http://127.0.0.1:8000/internal/status-domains/allow?domain=status.esempio-prova.com'
docker compose exec web php -r '$h = @get_headers("http://127.0.0.1:8000/internal/status-domains/allow?domain=status.esempio-prova.com"); echo $h[0], PHP_EOL;'
```

| Caso | Risultato atteso |
|---|---|
| H41 | Il primo comando risponde **404**, senza cookie né contenuto della pagina. Il secondo **403**, perché l'ammissione TLS accetta solo chiamate interne. Il terzo **404**, perché il dominio non è verificato |
| H42 | Premi **Remove hostname**: i dati del record spariscono |

**Facoltativo, solo se hai un dominio tuo.** Salva `status.tuodominio.it`, crea il record TXT indicato, attendi la propagazione e premi **Verify DNS ownership**. Aggiungi poi a `/etc/hosts` la riga `127.0.0.1 status.tuodominio.it`.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H43 | Apri `http://status.tuodominio.it:8000/` | Va alla status page |
| H44 | Sullo stesso host apri `/login`, `/teams` e `/status/altro-indirizzo` | 404 |
| H45 | Proteggi la pagina con la password e sbloccala sull'indirizzo principale | Sul dominio resta bloccata, e viceversa |
| H46 | Rimuovi il dominio e ricarica | 404. Togli la riga da `/etc/hosts` |

---

## Blocco I — Permessi e isolamento

Usa gli account del punto 1.4, ognuno in un profilo separato. Con `owner@signalbox.test` crea in **Synthetic Alpha** un monitor e una status page, e in **Synthetic Beta** un monitor con lo stesso nome. Annota gli ID: `{ALPHA}`, `{BETA}`, `{MONITOR_ALPHA}` e `{MONITOR_BETA}`.

### I1. Ruoli

| Caso | Account | Cosa fare | Risultato atteso |
|---|---|---|---|
| I01 | admin | Modifica un monitor; crea un canale di notifica e una status page | Tutto consentito |
| I02 | member | Modifica un monitor e metti in pausa | Consentito |
| I03 | member | Apri Notifications e Status pages di Alpha | Solo lettura, destinazioni mascherate, nessun pulsante di gestione |
| I04 | viewer | Apri lista e dettaglio monitor | Lettura sì, nessun pulsante di creazione, modifica o pausa |
| I05 | viewer | Apri a mano `/teams/{ALPHA}/monitors/create` e `/teams/{ALPHA}/monitors/{MONITOR_ALPHA}/edit` | 403 |
| I06 | viewer | Apri un incidente | Lettura, messaggio "Your role has read-only access to incidents" |

### I2. Isolamento tra team

| Caso | Account | Cosa fare | Risultato atteso |
|---|---|---|---|
| I07 | `tester@email.local` | Apri `/teams/{ALPHA}/monitors`, `/teams/{ALPHA}/dashboard` e `/teams/{ALPHA}/monitors/{MONITOR_ALPHA}` | 404 ovunque |
| I08 | `tester@email.local` | Apri l'incidente, le notifiche, le status page di GiGa Team | 404 |
| I09 | `tester@email.local` | Apri `/teams/{TEAM}/reports/2026-11` col team di un altro | 404, nessun PDF |
| I10 | owner | Sotto Alpha metti l'ID di Beta: `/teams/{ALPHA}/monitors/{MONITOR_BETA}` | 404, anche se è membro di entrambi |
| I11 | owner | Cerca il nome comune in Alpha, poi cambia team e cerca in Beta | Ogni lista mostra solo il proprio monitor |
| I12 | owner | Nella status page di Alpha cerca nel selettore dei monitor quello di Beta | Non compare |
| I13 | owner, admin, member | Apri `/admin` e `/horizon` | Accesso negato: i ruoli di team non valgono per la piattaforma |

### I3. Inviti e membri

Da `/teams/{TEAM}` con il tuo account:

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| I14 | **Invitation email** `nuovo@example.test`, **Invitation role** viewer, premi **Send invitation** | Email in Mailtrap, invito in **Invitations** |
| I15 | Apri il link in una finestra privata e registra l'account | Entri nel team come viewer |
| I16 | Cambia il suo ruolo, poi rimuovilo dal team | Applicato. Il suo accesso al team dà 404 alla ricarica |
| I17 | Crea un altro invito e revocalo, poi apri il link | Link non più valido |
| I18 | Cambia team da `/teams` e controlla le pagine | Dati del team scelto |
| I19 | Come owner principale cerca **Leave team** e, se c'è, premilo | Pulsante assente o azione rifiutata finché non trasferisci la proprietà |

---

## Blocco J — Limiti di piano

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| J01 | Con un team in trial Pro prova **Interval** 30 | Rifiutato: il minimo Pro è 60 |
| J02 | Dopo le 16:58 del 3 novembre apri Tester Team, ormai Free, e prova un monitor con **Interval** 60 | Rifiutato: il minimo Free è 300 |
| J03 | Sempre su Tester Team crea 5 monitor con interval 300 e prova il sesto | Rifiutato con un messaggio chiaro, sia dalla pagina sia al salvataggio |
| J04 | Metti in pausa uno dei cinque e riprova il sesto | Ancora rifiutato: la pausa non libera posti |
| J05 | Eliminane uno e riprova | Consentito |
| J06 | Crea una seconda status page sul team Free | Rifiutata: limite 1, le bozze contano |

La sospensione dei monitor oltre quota dopo un downgrade arriva con M9.05, quindi non è da provare adesso.

Crea i monitor di J03 con destinazione `https://example.com`, oppure mettili in pausa subito.

---

## Blocco K — Pannello amministratore

Promuovi il tuo account a operatore. Il comando chiede conferma:

```sh
docker compose exec web php artisan signalbox:platform-admin gigaprog7@gmail.com
docker compose exec web php artisan signalbox:platform-admin --list
```

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| K01 | Senza 2FA apri `/admin` | Rimanda alla sicurezza dell'account chiedendo di attivare il 2FA |
| K02 | Attiva il 2FA come in A08 e riapri `/admin` | Elenco di tutti i team |
| K03 | Apri un team, in **Plan override** scegli **Unlimited testing** e inserisci il motivo. Può chiederti di confermare la password | Salvato. Il team ora accetta intervalli di 30 secondi |
| K04 | Rimetti **No override (stored plan and trial apply)** | Tornano i limiti normali |
| K05 | Apri `/horizon` | Pannello visibile, con code e worker in funzione |

Alla fine, se non ti serve più, togli il ruolo:

```sh
docker compose exec web php artisan signalbox:platform-admin gigaprog7@gmail.com --revoke
```

---

## Blocco L — Accessibilità e usabilità

Su lista monitor, dettaglio, incidente, notifiche, status page pubblica:

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| L01 | Usa solo Tab, Shift+Tab e Invio | Tutti i controlli raggiungibili, focus sempre visibile |
| L02 | Invia un form con errori | Errore annunciato e vicino al campo |
| L03 | F12 → vista mobile (390 px) e zoom del browser al 200% | Azioni raggiungibili, tabelle consultabili |
| L04 | Rallenta la rete (F12 → Network → Slow 4G) e clicca due volte su un'azione | Indicatore di attesa, nessuna azione doppia |
| L05 | Imposta Offline, prova un'azione, torna Online | Errore comprensibile, nessuna rotellina infinita |
| L06 | Usa avanti, indietro e ricarica dopo aver salvato | Nessun invio doppio né pagina bloccata |
| L07 | Durante tutte le prove controlla la Console | Nessun errore JavaScript inatteso. I 403 e 404 provocati apposta sono normali |
| L08 | Ripeti i flussi principali in un secondo browser (Firefox o Safari) | Stesso comportamento |

---

## Se qualcosa non parte

**I monitor restano `unknown`:**

```sh
docker compose exec web php artisan config:show monitor-scheduling.enabled
docker compose exec web php artisan horizon:status
docker compose logs --tail=30 scheduler
docker compose logs --tail=50 queue
```

Il primo deve dire `true` e il secondo `Horizon is running`. Se hai cambiato `.env` senza riavviare, esegui di nuovo il secondo comando del punto 1.1. Non c'è un pulsante per forzare un controllo: si attende lo slot successivo.

**Stato dei monitor nel database:**

```sh
docker compose exec postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT id, team_id, name, is_paused, last_status, last_checked_at, next_check_at FROM monitors ORDER BY id;"'
```

**Le email non arrivano:** le email delle status page e delle notifiche partono dallo scheduler, con pochi secondi di ritardo. Controlla:

```sh
docker compose logs --tail=20 scheduler | grep -E 'dispatch'
grep -E '^MAIL_(MAILER|HOST|PORT)=' .env
```

Se Mailtrap rifiuta per limite di invio della sandbox gratuita, attendi o svuota la casella.

**Pagina bianca o errore sugli asset:**

```sh
docker compose run --rm node npm run build
```

**Errore 500:** cerca l'errore in `storage/logs/laravel.log` e riportami l'ultima eccezione, dopo aver controllato che non contenga password o token.

**Vedere lo storico a 90 giorni con dati:** i rollup giornalieri esistono solo per i giorni chiusi, quindi un monitor creato oggi mostra dati da domani. Puoi avanzarli a mano, e ripetere il comando non altera i risultati:

```sh
docker compose exec web php artisan metrics:rollups
```

---

## Pulizia finale

1. Ferma eventuali cicli `curl` dell'heartbeat con Ctrl+C.
2. Metti in pausa o elimina i monitor `PROVA-`.
3. In `.env` riporta `MONITOR_SCHEDULING_ENABLED=false` e, se l'avevi cambiato, `BROADCAST_CONNECTION=log`. Poi:

   ```sh
   docker compose stop reverb
   docker compose up -d --no-deps --force-recreate web queue scheduler
   docker compose exec web php artisan config:show monitor-scheduling.enabled
   ```

   L'ultimo comando deve rispondere `false`.

4. Revoca il ruolo operatore se non ti serve, come nel blocco K.
5. Togli da `/etc/hosts` le righe aggiunte in H43. Elimina i canali webhook verso webhook.site e svuota gli appunti.
6. Non usare `migrate:fresh`: cancellerebbe tutti i dati di sviluppo.

---

## Registro degli esiti

Data: ____ · Browser e versione: ____ · Secondo browser: ____

Puoi mandarmi anche solo i casi che non sono `PASS`.

| Blocco | Casi | Esito | Note |
|---|---|---|---|
| A — Accesso | A01–A10 | | |
| B — Monitor | B01–B21 | | |
| C — Heartbeat | C01–C06 | | |
| D — Notifiche | D01–D11 | | |
| E — Incidenti | E01–E17 | | |
| F — Manutenzioni e quiet hours | F01–F10 | | |
| G — Dashboard e report | G01–G07 | | |
| G — Tempo reale, facoltativo | G08–G10 | | |
| H — Status page | H01–H42 | | |
| H — Dominio reale, facoltativo | H43–H46 | | |
| I — Permessi e isolamento | I01–I19 | | |
| J — Limiti di piano | J01–J06 | | |
| K — Amministratore | K01–K05 | | |
| L — Accessibilità | L01–L08 | | |

### Difetti trovati

| Caso | URL e account | Atteso | Ottenuto | Gravità |
|---|---|---|---|---|
| | | | | |

### Note sulla grafica

Da usare come spunto per i task UI, non come difetti da correggere ora.

| Pagina | Cosa non funziona o cosa vorresti | Confronto con UptimeRobot |
|---|---|---|
| | | |
