# Prove manuali nel browser (fino a M8)

Guida passo per passo per provare l'applicazione com'è dopo M8, prima di iniziare M9. Non sostituisce i test automatici: controlla ciò che loro non vedono, cioè browser reale, email reali, aspetto delle pagine e flussi completi.

**Guida rivista il 2026-11-12 per la fase A UI.** È rivolta a chi usa l'applicazione e non ne conosce il codice. La revisione delle istruzioni non cambia gli esiti del collaudo.

**Stato storico al 2026-11-09:** l’utente conferma completati i blocchi obbligatori fino a K. Il blocco L — Accessibilità e usabilità è **rinviato su richiesta dell’utente a UI.06, dopo la nuova dashboard e navigazione applicativa**. Il registro delle precedenti prove dell’agente conserva 72 casi con 49 PASS, 0 FAIL, 18 BLOCCATO e 5 NON ESEGUITO: è una fotografia storica, non l’elenco attuale dei blocchi ancora aperti. Dettagli in [BROWSER-RESULTS-2026-11-09.md](BROWSER-RESULTS-2026-11-09.md). Nessun nuovo esito individuale viene dedotto per le prove facoltative realtime/dominio.

## Collaudo corrente — 2026-11-12

**UI.06.2 completato:** [registro dei flussi essenziali](BROWSER-RESULTS-2026-11-12.md), 22 flussi
per browser, 44 PASS in Brave e Firefox Playwright reali, headless. Include la nuova navigazione,
mutazioni dei monitor, incidenti, notifiche/status page, dinieghi e password inline dell’override.
Questi flussi supplementari non riscrivono gli esiti storici A–L né attestano la riesecuzione integrale
delle precedenti prove. Fixture ripristinate, due note private e audit conservati; servizi nuovamente arrestati.

**UI.06.3 completato:** mappa/passaggi aggiornati, 169 ID A–L conservati e prove correnti nel
[registro L](BROWSER-ACCESSIBILITY-2026-11-12.md). L02 completato tramite i riscontri VoiceOver
dell’utente sui due moduli in Brave/Firefox; L03 tramite zoom nativo 200% e ripristino. Limiti,
versioni, provenienza e differenze fra sonde/manuale sono espliciti; i risultati storici restano invariati.

**UI.06.4 completato e fase A accettata:** [consegna finale](PHASE-A-ACCEPTANCE.md), verifica dei
ripristini con 15 controlli READ ONLY positivi. VoiceOver non in esecuzione, zoom temporanei a 1,
profili effimeri eliminati, tutti i servizi arrestati e volumi conservati. Favicon 404 registrato
come non bloccante e assegnato a UI.07. Prossimo **M9.01**, non ancora avviato; la
[checklist nativa](BROWSER-NATIVE-CHECKS.md) resta per riprodurre le prove, senza richiederne una ripetizione.

### Fixture e risultati precedenti

Il 2026-11-09 l’utente ha confermato i blocchi obbligatori A–K conclusi e ha rinviato L alla nuova UI.
Il [registro storico](BROWSER-RESULTS-2026-11-09.md) conserva risultati, difetti e ripetizioni di quella
versione. Non ripartire da H13/H28 solo perché compaiono nelle note storiche.

Per il collaudo essenziale del 2026-11-12: Synthetic Alpha `{ALPHA}=3`, Beta `{BETA}=4`, monitor
7/8 in pausa e incidente Alpha 9 risolto; pagina pubblica esistente `/status/status-test`, **Stato Prova**,
letta senza modificarla. Ricontrollare questi identificativi in un’altra installazione. GiGa Team
resta il riferimento delle procedure A–K, con ID storico 2: non confonderlo con Alpha.
I trial e gli override storici non attestano il piano corrente: usare **Team → Plan and usage** e il
riepilogo **Monitors**. Non ricreare fixture già presenti, non rigenerare chiavi e non cambiare
configurazione/processi per le prove che non richiedono il motore o email.

G08–G10 (realtime) e H43–H46 (dominio reale) restano facoltativi e senza nuovi PASS. La correzione
quiet hours è descritta in [QUIET-HOURS.md](QUIET-HOURS.md); un avviso in attesa può ripartire alla
fine della fascia se l’incidente è ancora aperto. Prima delle prove di assenza verificare il provider.

**Accesso rapido:** [preparazione](#1-preparazione), [A accesso](#blocco-a--accesso-e-account), [B monitor](#blocco-b--monitor), [C heartbeat](#blocco-c--heartbeat), [D notifiche](#blocco-d--notifiche), [E incidenti](#blocco-e--incidenti), [F manutenzioni](#blocco-f--manutenzioni-e-quiet-hours), [G dashboard](#blocco-g--dashboard-e-report), [H status page](#blocco-h--status-page), [L dopo la nuova UI](#blocco-l--accessibilità-e-usabilità), [I permessi](#blocco-i--permessi-e-isolamento), [J limiti](#blocco-j--limiti-di-piano), [K amministrazione](#blocco-k--pannello-amministratore), [L accessibilità](#blocco-l--accessibilità-e-usabilità), [registro](#registro-degli-esiti).

## Come usare questo documento

- Alla prima esecuzione procedi in ordine: i blocchi successivi riusano i dati creati prima. Alla ripresa usa il punto registrato sopra, senza rifare i blocchi conclusi. Non c'è una durata totale garantita: intervalli dei monitor, email e DNS possono allungare le prove.
- Ogni caso ha un codice. Annota nella [tabella finale](#registro-degli-esiti) `PASS`, `FAIL`, `BLOCCATO` o `NON ESEGUITO`.
- Esegui i comandi dalla cartella del progetto:

  ```sh
  cd ~/Projects/signalbox
  ```

- I testi fra graffe sono segnaposto da sostituire, senza conservare le graffe. Usa la tabella qui sotto per annotare i tuoi valori.
- L'interfaccia è in inglese: i nomi di pulsanti e campi sono riportati esattamente come li vedi.
- La navigazione e le schermate private della fase A sono implementate. Registra problemi di leggibilità, focus o comandi irraggiungibili come difetti; i perfezionamenti estetici e il futuro restyling pubblico restano separati nelle [note sulla grafica](#note-sulla-grafica).

**Se qualcosa non va**, annota URL, account, cosa ti aspettavi, cosa è successo ed eventuali errori della Console del browser (F12). Non incollare mai token, password o codici. Sono da correggere prima di M9 i problemi di sicurezza, permessi, dati di altri team, errori 500 e perdita di dati.

### Indirizzi, finestre e dati da tenere a portata di mano

Usa sempre lo stesso indirizzo di base per l'applicazione: gli esempi della ripresa usano **`http://localhost:8000`**. Prima delle prove email leggi il solo valore **APP_URL** nel tuo `.env` e usa quell'indirizzo anche nel browser. Il template del progetto usa `http://127.0.0.1:8000`: se è il tuo valore, sostituiscilo agli esempi `localhost` qui sotto. Non occorre modificare APP_URL per seguire la guida.

`localhost` e `127.0.0.1` hanno sessioni browser diverse. **Non riscrivere l'host o la query di un link email firmato:** la modifica può invalidare la firma. Apri il link com'è nel profilo del destinatario; se il suo host è diverso, accedi con l'account previsto su quell'host e mantienilo per il resto delle prove. Se il link punta a un server irraggiungibile, registra la configurazione email **BLOCCATO**, senza tentare di correggere il collegamento a mano.

Un percorso come `/teams/2/monitors` si apre incollandolo **dopo l'indirizzo di base** nella barra del browser: `http://localhost:8000/teams/2/monitors`. Non è un comando da terminale.

| Segnaposto o dato | Dove trovarlo | Valore già noto per la ripresa |
|---|---|---|
| `{TEAM}` | Numero dopo `/teams/` aprendo il team da `/teams` | `2`, GiGa Team |
| `{TEAM_FREE}` | Numero di Tester Team da `/teams`, per J02–J06 | Da annotare: ____ |
| `{MONITOR}` | Numero dopo `/monitors/` cliccando `PROVA-HTTP` nella lista | Da annotare: ____ |
| `{INCIDENT}` | Numero dopo `/incidents/` aprendo la riga dell'incidente | Da annotare: ____ |
| `{SLUG}` | Campo **Public address** in **Edit {titolo}**, oppure link `/status/…` nella gestione | `status-test` |
| Titolo della pagina | Nome nella gestione **Status pages** | Da annotare: ____ |
| `{ALPHA}`, `{BETA}` | Numeri dei team Synthetic Alpha e Synthetic Beta, per il blocco I | Da annotare: ____ / ____ |
| `{MONITOR_ALPHA}`, `{MONITOR_BETA}` | Numeri dei due monitor creati nel blocco I | Da annotare: ____ / ____ |
| Intervallo e soglia HTTP | Dettaglio `PROVA-HTTP`: **Interval**, **Failures before alerting** | Ricontrollare: ____ / ____ |
| `{EMAIL_OPERATORE}` | Email del tuo account che userai nel pannello K | Da annotare privatamente |

Tieni aperti questi contesti e passa dall'uno all'altro quando il caso lo indica:

| Contesto | Account e pagina | A cosa serve |
|---|---|---|
| **Gestione** | Finestra normale, tuo account, GiGa Team | Modificare monitor, incidenti, notifiche e status page |
| **Pubblico** | Finestra privata senza accesso a Signalbox, `/status/status-test` | Vedere esattamente ciò che vede un visitatore |
| **Posta** | Scheda Mailtrap, sandbox configurata per questo ambiente | Leggere email e collegamenti, controllando destinatario e ora |
| **Ruoli** | Profili browser separati per owner/admin/member/viewer e account estraneo | Blocco I: verificare i permessi senza sovrascrivere altre sessioni |
| **Terminale** | Cartella del progetto | Solo i comandi nei blocchi di codice `sh` |

Le finestre private dello stesso browser possono condividere la sessione: due finestre private non garantiscono due account indipendenti. Usa profili distinti o browser diversi per i ruoli. Nel contesto **Pubblico** non effettuare login; apri gli inviti e la verifica account in un altro profilo quando necessario.

### Mappa delle schermate

Le pagine team mostrano il nome del team nell’intestazione e il menu comune. Su desktop usa
la sidebar; a larghezza ridotta **Open navigation** apre un dialogo: **Close navigation** o Escape
lo chiude e restituisce il focus. **Skip to content** porta al contenuto con la tastiera.
Il menu **Account menu**, etichettato visivamente col tuo nome, contiene **Your account**,
**Your teams**, **Account security**, **Sign out** e **Admin** solo per un operatore.

Per cambiare la preferenza scegli nel campo **Team** e premi **Switch team**: dopo la conferma
si apre la dashboard del team scelto. Il team nel percorso della pagina resta autorevole.
Se compare la protezione di una bozza, **Stay** conserva la bozza, **Discard and leave**
la abbandona; durante un invio bisogna attendere l’esito. Un cambio team non confermato offre
**Check current teams**, senza ripetere automaticamente il POST.

| Schermata | Percorso | Come orientarsi |
|---|---|---|
| Account | `/account` | Identità, preferenza corrente, Open dashboard e View your teams; non è una dashboard di un team |
| Scelta team | `/teams` | Campo Team + Switch team aggiorna la preferenza e apre la dashboard; il link col nome apre la dashboard del team |
| Dashboard | `/teams/{TEAM}/dashboard` | Conteggi e incidenti prioritari, Manage monitors/New monitor se consentito, tabella Monitors, grafici compatibili e Monthly reports |
| Monitor | `/teams/{TEAM}/monitors` | Search/Type/Status/State + Apply filters, paginazione; nome → dettaglio, New monitor se consentito |
| Nuovo/modifica monitor | `/teams/{TEAM}/monitors/create`, `/teams/{TEAM}/monitors/{MONITOR}/edit` | Form per tipo, sostituzione esplicita dei segreti, Create monitor/Save changes e Cancel; il salvataggio riuscito apre il dettaglio |
| Dettaglio monitor | `/teams/{TEAM}/monitors/{MONITOR}` | Stato e attività distinti, timing, storico 24 h, schede uptime/copertura/SLA, Monitor charts con periodo/regione/tabelle, contesto TLS/manutenzioni/notifiche/incidenti; Back to monitors conserva i filtri |
| Incidenti | `/teams/{TEAM}/incidents` | Filtri State/Severity e paginazione; numero → dettaglio; il ritorno conserva la selezione |
| Dettaglio incidente | `/teams/{TEAM}/incidents/{INCIDENT}` | Incident summary, Timeline privata/pubblicata, Notification deliveries e form autorizzati; Back to incidents e link al monitor |
| Manutenzioni team | `/teams/{TEAM}/maintenance` | Snapshot, filtri e Refresh; collegamento al monitor reale per creare/modificare finestre |
| Canali e policy | `/teams/{TEAM}/notifications` | Channels/Escalation policies/Notification deliveries, filtro locale Find channels or policies; gli editor nascosti conservano le bozze |
| Consegne | `/teams/{TEAM}/notifications/deliveries` | Snapshot, filtri, Refresh e link a incidenti/monitor; Sent significa accettazione del provider, non lettura del destinatario |
| Gestione status page | `/teams/{TEAM}/status-pages` | Gestione privata, Find a status page, Draft/Published; Edit {titolo} apre il form; una bozza non ha anteprima pubblica |
| Membri e inviti | `/teams/{TEAM}` | Menu Team; Members, Invitations, Plan and usage e controlli di proprietà secondo il ruolo |
| Pagina del visitatore | `/status/{SLUG}` | Layout pubblico distinto: titolo, stato, componenti/storico, Email updates e Published incidents; niente menu privato |
| Sicurezza account | `/account/security` | Può richiedere prima Confirm your password; autenticatore e rivelazioni temporanee solo su richiesta |
| Directory piattaforma | `/admin` → `/admin/teams` | Contesto Platform administration separato dalle membership; Search, dettaglio e ritorno filtrato; non seleziona un team di lavoro |
| Override e audit | `/admin/teams/{TEAM}` | Team summary, Plan override e History; conferma nativa dell’intento, password inline se scaduta; non concede membership |

**Esiti degli invii.** Waiting for the server to confirm… significa attesa; Change refused indica
un rifiuto noto. Outcome not confirmed conserva la bozza e blocca nuovi tentativi: usa
Review saved data (new tab), controlla il risultato e poi I checked the saved data prima di un
nuovo invio esplicito. Il ritorno online non ripete le scritture. Se l’accesso viene negato, i dati
privati sono nascosti e occorre autenticarsi o verificare l’accesso corrente.

### Operazioni comuni: guasto, ripristino e pubblicazione

Queste procedure sono richiamate nei casi con un nome esplicito. Non occorre conoscere rotte interne o modificare il database.

**Provocare il guasto HTTP**:

1. In **Gestione** apri `/teams/{TEAM}/monitors`, clicca `PROVA-HTTP`, poi **Edit monitor**.
2. Seleziona **Replace URL**; nel campo **URL** inserisci l'indirizzo completo `https://httpbin.org/status/503`. Mantieni **Expected status = 200**, così una risposta 503 è un fallimento. Premi **Save changes** in fondo.
3. Nel dettaglio controlla **Active**, **Interval**, **Failures before alerting** e **Last check**. Se è in pausa, usa **Resume monitor → Confirm resume**. Non deve esserci una manutenzione in corso, salvo nei casi F01–F03.
4. Ricarica il dettaglio ogni 20–30 secondi. Con intervallo 60 s, soglia 2 e sola regione EU attendi indicativamente 2–3 minuti. Con intervallo 300 s, soglia 2 possono servire 10–11 minuti. Sono attese operative, non tempi garantiti del servizio di destinazione.
5. Il guasto è pronto quando lo stato è **Down** e in **Recent incidents** c'è un incidente non **Resolved**. Aprilo e annota numero, URL e ora del ciclo corrente: **Confirmed** per un nuovo incidente; l'ultima ora nella voce **Reopened** se si è riaperto un incidente recente. Un primo check fallito può rendere il monitor **Down** senza aver ancora aperto un incidente.

Nei casi successivi **ora di conferma del guasto** significa questa ora del ciclo corrente. **Confirmed** resta l'ora originale quando un incidente si riapre: per una riapertura non contare i minuti a partire dal vecchio valore.

Se **Last check** non avanza entro due intervalli più un minuto, fermati e usa [Se qualcosa non parte](#se-qualcosa-non-parte): prima va risolto il blocco dei check, poi si può valutare il caso. Non attribuire il ritardo alla cache della pagina pubblica.

**Ripristinare HTTP**:

1. Nel dettaglio `PROVA-HTTP` apri **Edit monitor**, seleziona **Replace URL**, inserisci `https://httpbin.org/status/200` e premi **Save changes**. Mantieni **Expected status = 200**.
2. Ricarica finché **Last check** avanza, lo stato diventa **Up** e il relativo incidente diventa **Resolved**. Con intervallo 60 s considera circa 1–2 minuti; con 300 s circa 5–6 minuti.
3. Il cambio automatico segue il nuovo check: scrivere una nota **Resolved** mentre l'URL è ancora guasto non prova il recupero del monitor.

**Aggiungere una nota privata o pubblicarla**:

1. In **Gestione** apri l'incidente da **Recent incidents**, oppure da `/teams/{TEAM}/incidents`.
2. Scorri a **Add a timeline update**. In **Status after this update** mantieni lo stato corrente per una semplice nota; su un incidente già risolto usa **Resolved**. Inserisci in **Message** il testo indicato dal caso e premi **Add update**.
3. La nota compare nella **Timeline**, ordinata dalla più vecchia alla più recente, con etichetta **Private**. Per una prova privata fermati qui.
4. Per pubblicarla, trova proprio quella nota nella **Timeline**, premi il suo **Publish update**, leggi il riquadro inline e premi **Confirm publication**. **Cancel publication** o Escape annulla soltanto la conferma non inviata. L'etichetta diventa **Published …**. La pubblicazione non si ritira: usa soltanto testi di prova destinati ai visitatori.
5. Torna in **Pubblico**, ricarica e scorri a **Published incidents**: il testo deve apparire sotto il nome pubblico del componente che include il monitor. Prima di **Publish update**, il testo non deve essere visibile lì.

### Come attribuire l'esito

- **PASS**: hai eseguito tutte le azioni del caso e osservato tutti i risultati indicati.
- **FAIL**: i prerequisiti sono validi, ma il risultato è diverso. Annota cosa leggi e dove.
- **BLOCCATO**: manca un prerequisito, per esempio un check nuovo, il piano richiesto, l'email o il dominio. Non equivale a un difetto della funzione da provare.
- **NON ESEGUITO**: il caso è stato saltato, comprese le prove facoltative non scelte.

Un caso superato non implica che lo siano gli altri. Per controllare un 403/404/429, apri gli strumenti sviluppatore, scheda **Network** (Rete), esegui di nuovo l'azione e guarda la colonna **Status** della richiesta interessata. Un errore di validazione deve lasciare un messaggio vicino al campo; non richiede necessariamente una pagina di errore.

---

## 1. Preparazione

### 1.1 Prepara soltanto i servizi necessari

**Per L e lo smoke UI senza check/consegne nuovi** basta `docker compose up -d web` (con PostgreSQL/Redis dipendenti), asset aggiornati e schema già applicato. Lascia scheduler/worker fermi se non servono e annota lo stato iniziale da ripristinare. Non modificare `.env` per queste sole prove.

**Per i casi B–H che richiedono check e notifiche reali**, apri il file `.env` nella cartella principale del progetto. Cerca `MONITOR_SCHEDULING_ENABLED`: se esiste modifica quella riga, altrimenti aggiungila. Non lasciare due definizioni dello stesso valore.

```sh
MONITOR_SCHEDULING_ENABLED=true
```

Nel terminale avvia prima database e Redis, poi ricompila le pagine e ricrea i processi dell'applicazione. Questi comandi modificano i processi locali e possono essere necessari alla ripresa; non cancellano i dati:

```sh
docker compose up -d postgres redis
docker compose run --rm node npm run build
docker compose up -d --no-deps --force-recreate web queue scheduler
```

Controlla che sia tutto attivo:

```sh
docker compose ps
docker compose exec web php artisan horizon:status
docker compose exec web php artisan config:show monitor-scheduling.enabled
docker compose exec web php artisan migrate:status
```

**Controlla l'output di ciascun comando:** `web`, `queue`, `scheduler`, `postgres` e `redis` devono essere in esecuzione; Horizon deve risultare attivo; la configurazione deve valere `true`; tutte le migrazioni devono risultare **Ran**, nessuna **Pending**. Se ci sono migrazioni in attesa, segna la preparazione **BLOCCATO** e annotane i nomi: l'adozione va verificata in BOOTSTRAP prima di continuare. Non usare reset del database. Reverb serve solo per G08–G10; se la configurazione usa già `reverb`, avvia anche quel servizio come indicato nel blocco G.

Apri `http://localhost:8000/login`. Se il browser non raggiunge la pagina, non iniziare i casi: controlla l'output di `docker compose ps` e i log del servizio `web`. Se la configurazione continua a mostrare il vecchio valore, usa `docker compose exec web php artisan config:clear`, ricrea `web queue scheduler` con il comando sopra e ricontrolla.

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

Per ogni prova filtra o cerca il **destinatario** e confronta l'ora con l'azione appena eseguita: una vecchia email non prova una nuova consegna. In H vengono usati gli aggiornamenti ai visitatori; in D–F gli avvisi operativi ai canali del team. Sono flussi distinti e possono avere destinatari diversi.

Se non arriva nulla entro un minuto, controlla i log del punto 1.2 e la configurazione Mailtrap. Non ripetere subito l'invio più volte. Se il provider segnala una quota esaurita, lo svuotamento dell'inbox non garantisce il ripristino della quota: attendi il rinnovo o risolvi il limite e segna il caso **BLOCCATO** nel frattempo.

### 1.4 Account di prova

Esistono già due account verificati, `tester@email.local` e il tuo. Crea anche gli account con i quattro ruoli, senza toccare i dati esistenti:

```sh
docker compose exec web php artisan db:seed --class=IdentitySeeder
docker compose exec postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "UPDATE users SET email_verified_at = now() WHERE email LIKE '"'"'%@signalbox.test'"'"' AND email_verified_at IS NULL;"'
```

Esegui questi due comandi solo se devi preparare il blocco I: creano dati sintetici e segnano verificati gli account `@signalbox.test`. Il primo crea gli account mancanti `owner@signalbox.test`, `admin@signalbox.test`, `member@signalbox.test` e `viewer@signalbox.test`, con password iniziale `password`, e i team Synthetic Alpha e Synthetic Beta. Non reimposta password o trial di account/team già esistenti, ma riapplica i quattro ruoli nei due team sintetici. I quattro account appartengono a **entrambi** i team. Il secondo li segna come verificati, perché il seeder non lo fa. Non eseguire il seeder durante una prova di modifica dei ruoli.

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

Alla data della revisione entrambi i trial sono scaduti. Per conoscere i limiti realmente applicati, apri **Monitors** del team: sopra i filtri trovi il riepilogo dei posti e **Minimum interval**. Se è `5 min`, non puoi usare i tempi delle prove a 60 secondi.

Per B–H puoi scegliere una delle due preparazioni:

1. **Mantenere le prove a 60 secondi:** con il tuo account operatore, segui l'accesso del [blocco K](#blocco-k--pannello-amministratore), apri GiGa Team, seleziona **Granted Pro plan**, compila **Reason (required, recorded in the audit log)** con `Collaudo browser a 60 secondi`, poi **Save override** e accetta il dialogo nativo. Se compare la richiesta password nella stessa pagina, compila **Current password** e premi **Confirm password and save override**: l’operazione viene eseguita dopo la conferma, senza ripetere il salvataggio. Annota la configurazione precedente per ripristinarla alla fine. Torna in **Monitors** e controlla **Minimum interval 1 min**. In locale l'accesso operatore non richiede necessariamente la 2FA; la regola esatta è nel blocco K.
2. **Usare Free:** in ciascun monitor a 60 s apri **Edit monitor**, cambia **Interval (seconds)** in `300`, premi **Save changes**. Usa le attese più lunghe descritte nelle operazioni comuni. Free consente cinque monitor in totale: B14 richiede un posto aggiuntivo, quindi quella prova rimane bloccata finché non c'è quota o un piano Pro. La pausa non libera un posto.

Se non puoi accedere all'amministrazione e la prova richiede il piano Pro, segna il caso **BLOCCATO**. Non riavviare ripetutamente i processi per aggirare il limite: il piano viene controllato anche nell'esecuzione dei check. Per J02–J06 servirà invece un team effettivamente Free senza override.

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

**Preparazione:** tieni il tuo account principale nella finestra **Gestione**. A01–A10 si eseguono in un altro profilo, usando per A04 un indirizzo nuovo, per esempio `accesso-02@example.test`. Mailtrap deve essere disponibile. Per uscire usa **Account menu → Sign out** nell’intestazione privata; il logout porta alla home pubblica. Un successivo ingresso privato deve richiedere login.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| A01 | In un profilo senza login apri `http://localhost:8000/` | Home con **Sign in** e testo provvisorio **The application is being built** (UI.05); nessun dato del team |
| A02 | Nello stesso profilo senza login incolla `/teams` nella barra indirizzi | Sei portato a `/login`; nessun elenco team visibile |
| A03 | Su `/login` inserisci in **Email** un account inesistente e una password qualsiasi, poi **Sign in**. Ripeti con il tuo account e la password corretta | Primo tentativo rifiutato con messaggio; secondo riuscito. Apri `/account` e verifica la tua email |
| A04 | In un profilo separato apri `/register`. Compila **Your name** `Collaudo Accesso`, **Team name** `Collaudo Accesso`, **Email** con un nuovo indirizzo sintetico, **Password (12–128 characters)** e **Confirm password** uguali; premi **Create account**. Annota privatamente indirizzo e password | Pagina **Verify your email** e nuova email al destinatario indicato in Mailtrap |
| A05 | Con il nuovo account di A04 ancora non verificato apri `/teams` | Torni a **Verify your email**, senza dati dei team |
| A06 | In Mailtrap apri l'email di A04 e il suo collegamento di verifica nello stesso profilo dove hai registrato l'account. Poi visita `/teams` | Verifica riuscita; puoi aprire il team creato in A04 |
| A07 | Nel profilo di A04 usa **Account menu → Sign out**, poi visita `/forgot-password`. Inserisci la stessa email e premi **Send reset link**. Apri il nuovo link Mailtrap, compila la nuova password e la conferma, premi **Reset password**. Su `/login` prova prima la vecchia password e poi la nuova | La vecchia è rifiutata; la nuova permette **Sign in**. Annota privatamente la nuova password per A08 |
| A08 | Con l'account A04 vai su `/account/security`, premi **Enable two-factor authentication**. Se appare **Confirm your password**, inserisci la password attuale e premi **Confirm password**, poi riprendi la configurazione. Se il QR non è già visibile premi **Show authenticator setup**. Scansionalo con un'app autenticatore, inserisci il codice corrente in **Authentication code** e premi **Confirm authenticator**; poi **Show recovery codes** e conserva privatamente almeno due codici per A10 | Testo **Two-factor authentication is enabled.** e codici di recupero visibili su richiesta. Non inserire codici o QR nel registro degli esiti |
| A09 | Con il medesimo account di A08 usa **Account menu → Sign out**, poi `/login → Sign in`. Nella schermata **Two-factor authentication** prova un codice errato; poi inserisci un codice attuale del tuo autenticatore e premi **Verify and sign in** | Prima compare un errore; poi accedi. La sola password non completa il login |
| A10 | Esci e avvia un nuovo login. Nella schermata 2FA premi **Use a recovery code**, inserisci un codice salvato in A08, poi **Verify and sign in**. Esci di nuovo e riprova lo stesso codice: deve fallire. Usa un codice diverso per accedere, vai a `/account/security` e premi **Disable two-factor authentication** | Ogni codice di recupero funziona una sola volta; al termine 2FA disattivata solo per l'account di A04 |

Fai A08–A10 con l'account creato in A04, non con il tuo, a meno che ti serva per il blocco K.

---

## Blocco B — Monitor

**Preparazione:** nella finestra **Gestione** accedi con il tuo account, apri `/teams`, clicca **GiGa Team**, poi **Monitors**. Annota `{TEAM}` dal percorso. Verifica il piano come in 1.5 e la quota prima di creare: per B01–B05 servono cinque posti e per B14 un sesto temporaneo. Non ricreare i monitor già esistenti alla ripresa. Per aprire un monitor clicca il suo nome nella colonna **Name**.

### B1. Creazione

Crea questi cinque monitor, uno alla volta: dalla lista **New monitor**, compila il modulo, poi **Create monitor** in fondo. Per tornare alla lista usa **Back to monitors**. Le colonne **Interval** e **Failures before alerting** della tabella indicano i valori da inserire nei campi **Interval (seconds)** e **Failures before alerting**. **Timeout (milliseconds)** vale `5000`; in **Regions** lascia selezionato solo **Europe (simulated)**. Per TLS la scelta **Type** si chiama **TLS certificate**. I valori a 60 s richiedono il piano indicato in 1.5.

| Caso | Name | Type | Interval | Altri campi | Failures before alerting |
|---|---|---|---|---|---|
| B01 | `PROVA-HTTP` | HTTP | 60 | **URL** `https://httpbin.org/status/200`, **Method** GET, **Expected status** 200 | 2 |
| B02 | `PROVA-KEYWORD` | Keyword | 60 | **URL** `https://example.com`, **Keyword** `Example Domain`, **Keyword must be** `present` | 2 |
| B03 | `PROVA-TLS` | TLS certificate | 300 | **Host** `example.com`, **Port** 443, **Expiry warning (days)** 7 | 3 |
| B04 | `PROVA-DNS` | DNS | 300 | **Query name** `one.one.one.one`, **Record type** A, **Expected values** `1.1.1.1` e, con **Add value**, `1.0.0.1`. **Resolver** vuoto | 3 |
| B05 | `PROVA-HEARTBEAT` | Heartbeat | 60 | **Grace period (seconds)** 30 | 2 |

**Atteso per ognuno:** compaiono solo i campi del tipo scelto, poi il dettaglio mostra **Monitor created.**, nome corretto, impostazioni salvate e **Active**. Lo stato iniziale è **Unknown**; un check molto rapido può averlo già aggiornato. Il dettaglio include **Monitor charts** e lo storico 24 ore; i grafici precedenti della Dashboard restano compatibili, blocco G. I dati mancanti sono dichiarati.

### B2. Stato reale

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| B06 | Nella lista **Monitors**, righe `PROVA-HTTP` e `PROVA-KEYWORD`, guarda **Status** e **Last check**. Con intervallo 60 s attendi 2–3 minuti, ricaricando ogni 20–30 s; con Free a 300 s considera almeno un intervallo completo più un minuto | **Up** in entrambe le righe, **Last check** con data/ora recente. Se non avanza, usa il troubleshooting prima dei casi successivi |
| B07 | Nella stessa lista controlla `PROVA-TLS` e `PROVA-DNS`, a intervallo 300 s. Attendi 6–7 minuti dalla creazione e ricarica | Anche queste righe mostrano **Up** e **Last check** recente. Per un errore DNS/TLS annota il tipo di monitor e l'ora, senza dedurre un guasto del motore dai soli servizi di destinazione |

Se resta tutto `unknown`, controlla la sezione [Se qualcosa non parte](#se-qualcosa-non-parte).

### B3. Validazione e sicurezza

Apri **New monitor** per ciascuna prova. Prima compila tutti gli altri campi con valori validi: altrimenti un errore su un campo diverso non dimostra il rifiuto che stai verificando. Dopo l'errore non deve comparire una nuova riga nella lista. Se un valore vietato viene salvato, registra **FAIL** e interrompi quella prova.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| B08 | Compila un monitor HTTP valido come B01, poi svuota solo **Name** e premi **Create monitor** | Errore vicino a **Name**, nessun monitor creato |
| B09 | Nel modulo HTTP valido imposta solo **Interval (seconds)** a `10`, poi **Create monitor** | Errore di intervallo; nessun monitor creato. Rimetti il valore valido prima del caso successivo |
| B10 | Nel modulo HTTP valido sostituisci **URL** con `http://127.0.0.1/`, poi **Create monitor** | URL rifiutato senza dettagli della rete; nessun monitor creato |
| B11 | Ripeti B10 con **URL** `http://192.168.1.1/` | URL rifiutato; nessun monitor creato |
| B12 | Ripeti B10 con **URL** `https://utente:segreto@example.com/` | URL con credenziali rifiutato; nessun monitor creato |
| B13 | Scegli **Type = TLS certificate**, compila i campi generali e lascia **Host** vuoto; invia. Poi scegli **DNS**, compila **Query name** e lascia vuoti gli **Expected values**; invia | Errori sui campi mancanti per entrambi i tipi. Non salvare una configurazione valida in questa sezione |

### B4. Segreti

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| B14 | Con quota disponibile apri **New monitor**, usa i valori HTTP di B01 e **Name** `PROVA-SEGRETI`. Imposta **URL** `https://httpbin.org/status/200?api_key=segreto-prova`; premi **Add header**, nel primo campo scrivi `Authorization`, nel secondo `Bearer segreto-prova`; premi **Create monitor** | Dettaglio del nuovo monitor. Annota il suo URL per B17; usa soltanto questi segreti sintetici |
| B15 | Nel dettaglio di PROVA-SEGRETI cerca `segreto-prova` con ⌘F/Ctrl+F; poi apri **Edit monitor** senza selezionare caselle di sostituzione e ripeti. Apri anche il sorgente HTML della pagina di modifica e cerca lo stesso testo (istruzioni sorgente in H2) | Segreto assente; URL con valore query nascosto, header mostrato solo per nome. Il testo che hai appena digitato in un campo di sostituzione non vale come fuga: qui controlli ciò che il server restituisce dopo il salvataggio |
| B16 | In **Edit monitor** modifica solo **Name** in `PROVA-SEGRETI-RINOMINATO`, lasciando deselezionati **Replace URL** e **Replace all headers**; premi **Save changes**. Attendi un nuovo **Last check** | Nuovo nome salvato e monitor ancora **Up**. Il mantenimento esatto dei segreti è coperto dai test automatici: questa prova verifica il comportamento visibile, non legge credenziali nascoste |
| B17 | Dal dettaglio del solo monitor temporaneo di B14 premi **Delete monitor**, poi **Cancel** nel riquadro inline: il monitor deve restare. Ripeti con **Confirm delete**, poi visita il suo URL annotato | Torni alla lista; monitor temporaneo assente; vecchio URL **404**. Non eliminare PROVA-HTTP o gli altri monitor riutilizzati |

### B5. Lista e pausa

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| B18 | Nella lista **Monitors**, campo **Search**, scrivi `PROVA-`. Scegli **Type = HTTP** e uno **Status**, poi **Apply filters**; controlla nomi e tipo. Premi **Clear filters** | Ogni risultato rispetta i filtri; dopo la pulizia torna la lista completa, comprese eventuali altre pagine |
| B19 | In **Search** scrivi `inesistente`, poi **Apply filters** | **No monitors match these filters on this page.**; poi **Clear filters** per continuare |
| B20 | Nella barra indirizzi apri `/teams/{TEAM}/monitors?type=nonexistent` | Avviso **Some filters were invalid and have been ignored: type.**; lista ancora utilizzabile. Torna alla lista senza query |
| B21 | Apri `PROVA-TLS` dalla lista; nelle azioni in alto **Pause monitor → Confirm pause**, verifica **Paused**, poi **Resume monitor → Confirm resume** | Lo stato operativo torna **Active**. Lo stato osservato **Up/Down/Unknown** è distinto dalla pausa |

---

## Blocco C — Heartbeat

**Preparazione:** in **Gestione → Monitors** clicca `PROVA-HEARTBEAT`. Deve essere **Active**, ammesso dal piano, con **Grace period** 30 s. Tieni un terminale dedicato al ciclo di ping: quel terminale resterà occupato finché non premi Ctrl+C. La credenziale è il token che permette al job di inviare il segnale; non è la password dell'account.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| C01 | Nel dettaglio PROVA-HEARTBEAT scorri alla sezione **Heartbeat**, premi **Generate credential**. Se esiste già una credenziale, usa **Rotate credential** e conferma, aggiornando il tuo eventuale ciclo | Compare il comando temporaneo e la richiesta di salvarlo; **Credential: Configured** |
| C02 | Premi **Copy heartbeat command**, conserva il comando privatamente prima di ricaricare, incollalo in un terminale ed eseguilo una volta | Nessun errore né testo; la chiamata valida risponde **204**. Se vuoi vederne il codice, aggiungi `-i` al comando copiato senza cambiare URL o header |
| C03 | Ricarica il dettaglio del monitor e scorri a **Heartbeat** | Comando/token non più visibili; **Last heartbeat** mostra la ricezione recente. **Last check** riguarda invece il controllo schedulato |
| C04 | Usando il token conservato privatamente, apri gli strumenti sviluppatore: Chrome **Application → Storage → Local storage / Session storage**, Firefox **Storage**. Controlla entrambe le aree per questo host | Il token non compare. Non cercarlo nelle richieste Network, dove è necessario per inviare il ping |
| C05 | Nel terminale dedicato avvia il ciclo sotto, sostituendo il token e mantenendo il tuo host; lascialo aperto. Nel dettaglio del monitor ricarica fino a un nuovo **Last check** | **Last heartbeat** avanza e lo stato osservato diventa **Up**. Non basta che il comando termini: il monitor deve essere **Active** e ammesso dal piano |

```sh
while true; do curl --fail --request POST --header 'Authorization: Bearer IL_TUO_TOKEN' http://localhost:8000/heartbeats; sleep 45; done
```

Verifica i rifiuti in un altro terminale:

```sh
curl -i -X POST http://localhost:8000/heartbeats
curl -i -X POST -H 'Authorization: Bearer token-inventato' http://localhost:8000/heartbeats
curl -i http://localhost:8000/heartbeats
```

| Caso | Risultato atteso |
|---|---|
| C06 | I primi due comandi rispondono **404** senza dettagli sul monitor; il terzo **405** |

Nel ciclo sopra sostituisci `IL_TUO_TOKEN` con il token del comando salvato e usa lo stesso endpoint copiato, senza incollare due volte `Bearer`. Ogni 45 secondi il ciclo invia un segnale. Non condividere il comando compilato nel registro. Lascia il ciclo attivo: lo fermerai in E14, poi proverai rotazione e revoca in E16–E17.

---

## Blocco D — Notifiche

Vai su **Notifications** (`/teams/{TEAM}/notifications`).

**Preparazione:** usa il tuo account owner in **Gestione**, Mailtrap in **Posta** e una scheda separata per webhook.site. Alla ripresa riutilizza **Email prova**, **Webhook prova** e **Policy prova** esistenti. Prima di E verifica che i canali siano **Active**, senza quiet hours, e che la policy effettiva di PROVA-HTTP sia quella di D10. Non confondere il codice di verifica del canale con la conferma email di un visitatore della status page (H5).

### D1. Canali

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| D01 | In **Gestione → Notifications**, sezione **Add a channel**, scegli **Channel type = Email**, **Name** `Email prova`, **Email address** `prova@example.test`, poi **Add channel** | Nell’elenco appare **Email prova**, stato **Not verified**; non è ancora attivo |
| D02 | Nella riga **Email prova** premi **Send verification code**; in Mailtrap cerca la nuova email a `prova@example.test` | Codice di sei cifre; nella riga compaiono **Waiting for code**, campo **Verification code** e **Verify** |
| D03 | Sulla riga **Email prova** inserisci sei cifre diverse dal codice ricevuto in **Verification code** e premi **Verify** | Messaggio vicino al campo; canale ancora **Waiting for code**, non **Active**. Non richiedere un codice nuovo prima di provare quello corretto in D04 |
| D04 | Nella stessa riga inserisci il codice corretto della più recente email in **Verification code**, poi **Verify** | Stato **Active**, data **Verified …**; non occorre un secondo comando di attivazione |
| D05 | Apri webhook.site e conserva privatamente l’URL assegnato. In **Add a channel** scegli Webhook, **Name** `Webhook prova`, **Endpoint URL** con quell’URL, poi **Add channel**. Sulla nuova riga premi **Send verification code**. Su webhook.site apri la richiesta ricevuta, leggi il codice nel corpo; torna a Signalbox, compilalo in **Verification code** e premi **Verify** | Canale **Webhook prova** in stato **Active**. Nei dettagli della richiesta ricevuta trovi `X-Signalbox-Id`, `X-Signalbox-Timestamp`, `X-Signalbox-Signature` |
| D06 | Sulla riga **Webhook prova** premi **Create new signing secret** e conferma nella finestra browser; poi **Hide secret** e ricarica | Segreto visibile solo dopo la creazione esplicita; dopo **Hide secret** e ricarica non è più leggibile. La rotazione rende inutilizzabile il precedente: usa il canale sintetico di D05 |
| D07 | In **Add a channel** scegli SMS, **Name** `SMS prova`, **Phone number** `+393331234567`, poi **Add channel**. Prova **Send verification code** sulla riga | Testo **Stub channel: deliveries are recorded but never sent.**; verifica rifiutata, nessun SMS reale e nessuna attivazione |
| D08 | In **Add a channel** scegli Webhook, compila un nome temporaneo e **Endpoint URL** `http://127.0.0.1/`, poi **Add channel** | Errore: destinazione non consentita. Nessun nuovo canale creato |
| D09 | **Facoltativo:** con un'integrazione tua di prova crea Slack (**Incoming webhook URL**) o Telegram (**Bot token**, **Chat ID**), usando un nome riconoscibile e **Add channel**. Premi **Send verification code**, leggi il messaggio in Slack/Telegram e riporta il codice in **Verification code → Verify** | Canale **Active**. Senza integrazione disponibile segna **NON ESEGUITO**; non inserire credenziali reali nel registro |

Webhook.site è un servizio di terzi: non inviargli dati veri. In queste prove riceve solo dati sintetici.

### D2. Policy di escalation

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| D10 | Scorri a **Add a policy**. Compila **Policy name** `Policy prova`, spunta **Team default: used by monitors that do not choose another policy**. In **Step 1**, campo **Minutes after the incident is confirmed**, inserisci `0` e seleziona solo **Email prova**. Premi **Add step**: nel secondo inserisci `5` minuti e seleziona solo **Webhook prova**. Premi **Add policy** | Policy nell’elenco, predefinita, primo step immediato Email e secondo dopo 5 minuti Webhook. Entrambi i canali devono risultare attivi |
| D11 | Apri **Monitors → PROVA-HTTP**, scorri a **Notifications** e leggi la policy effettiva. Se il monitor ha una policy esplicita diversa, in **Escalation policy** scegli **Team default (Policy prova)** e premi **Save** nel pannello | **Incidents follow the policy Policy prova (team default).**: E e F devono usare proprio i canali preparati sopra |

### D3. Quiet hours

Le prove sulle quiet hours sono nel [blocco F](#blocco-f--manutenzioni-e-quiet-hours), dopo aver visto una notifica normale.

---

## Blocco E — Incidenti

**Preparazione:** PROVA-HTTP **Active**, URL iniziale `https://httpbin.org/status/200`, nessuna manutenzione attiva, check recenti; D10/D11 configurati; Email/Webhook **Active** e senza quiet hours. Risolvi eventuali guasti precedenti con **Ripristinare HTTP** prima di E01. Mantieni aperti dettaglio monitor e dettaglio incidente in schede **Gestione** diverse, più **Posta** e webhook.site. Le attese di apertura dipendono da intervallo e soglia: usa le operazioni comuni.

### E1. Guasto reale

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| E01 | Segui **Provocare il guasto HTTP** nelle operazioni comuni, a partire da PROVA-HTTP operativo e senza manutenzione; annota numero e ora di conferma dell’incidente | Monitor **Down**; incidente aperto in **Recent incidents**. Clicca il numero per il dettaglio, oppure trovalo in `/teams/{TEAM}/incidents` |
| E02 | Da **Posta**, dopo l’ora di conferma di E01, cerca la nuova email a `prova@example.test`; attendi fino a un minuto | Avviso di apertura per PROVA-HTTP. Non confonderlo con i codici di verifica del blocco D |
| E03 | Nel dettaglio dell’incidente E01 controlla stato in alto, **Confirmed**, **Resolved: Not yet**, **Timeline** e più sotto **Notification deliveries** | Incidente aperto; la riga Email riporta **Incident opened** in **Reason** e **Sent** in **Status** dopo la consegna |
| E04 | Lascia l’URL guasto e l’incidente aperto. Attendi 5 minuti dall'ora di conferma del guasto corrente (**Confirmed**, oppure ultima ora **Reopened**), poi fino a un minuto per la consegna. Controlla webhook.site e ricarica **Notification deliveries** nell’incidente | Richiesta Webhook di escalation e riga **Escalation · step 2**, **Sent**; non contare la richiesta di verifica D05 come avviso |

### E2. Gestione

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| E05 | Nell’incidente aperto E01 premi **Take responsibility** e guarda la voce **Responsible** sopra la Timeline | Il tuo nome compare con l’ora di presa in carico |
| E06 | Nello stesso incidente usa **Aggiungere una nota privata o pubblicarla**: **Message** `Nota privata di prova`, mantieni lo stato corrente e premi solo **Add update** | In **Timeline** nota con etichetta **Private**; non premere **Publish update** su questa nota |
| E07 | Aggiungi una seconda nota con **Message** `Stiamo verificando il problema`, mantenendo lo stato corrente. Premi **Add update**, poi **Publish update** su questa nuova nota e conferma | Etichetta **Published …** su questa sola nota. Sarà usata per H06/H08 |
| E08 | Nella gestione dell’incidente cambia **Severity** in un valore diverso e premi **Save severity**. In **Declared cause** scrivi `Causa privata di prova`, poi **Save cause** | Nuova severità in alto e causa nel riepilogo. La causa resta privata |
| E09 | Ricarica il medesimo URL dell’incidente e controlla **Responsible**, severità, causa e le due note di E06/E07 | Dati mantenuti, nota E06 ancora **Private**, nota E07 ancora **Published …** |

### E3. Risoluzione e riapertura

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| E10 | Segui **Ripristinare HTTP** nelle operazioni comuni; ricarica dettaglio monitor e incidente E01 | **Up**, incidente **Resolved**, data **Resolved** e durata valorizzate |
| E11 | Dopo E10 cerca in Mailtrap e webhook.site i messaggi relativi allo stesso incidente; controlla **Notification deliveries** | Un **Resolution summary** per ciascun canale che aveva ricevuto un avviso di questo incidente |
| E12 | Confronta le righe **Sent** di **Notification deliveries** con email e richieste Webhook dello stesso incidente/ciclo. Ricarica e ricontrolla dopo un minuto | Nessun invio aggiuntivo della stessa apertura, dello stesso step o della stessa risoluzione. Apertura, escalation e risoluzione sono messaggi distinti previsti |
| E13 | Segui di nuovo **Provocare il guasto HTTP**, annota il numero dell’incidente aperto e controlla la lista; poi **Ripristinare HTTP** | Un solo incidente aperto per questo monitor. Se il guasto è ravvicinato può riaprire lo stesso incidente: annota il contatore **Reopened** invece di aspettarti sempre un numero nuovo |

### E4. Heartbeat mancato

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| E14 | Nel terminale del ciclo heartbeat del blocco C premi Ctrl+C. Nel dettaglio PROVA-HEARTBEAT annota **Deadline** e ricarica dopo la scadenza, attendendo i fallimenti necessari alla soglia | **Down** e incidente aperto in **Recent incidents**. Con 60 s e soglia 2 considera circa 3–4 minuti; con 300 s la prova richiede più tempo |
| E15 | Riavvia lo stesso ciclo con la credenziale valida, ricarica il dettaglio dopo un nuovo check e apri l’incidente heartbeat | **Last heartbeat** recente, **Up** e incidente **Resolved** |
| E16 | Ferma il ciclo, conserva il comando vecchio privatamente. Nel pannello **Heartbeat** premi **Rotate credential** e conferma. Copia subito il nuovo comando; esegui quello vecchio e poi il nuovo, aggiungendo `-i` se vuoi leggere il codice. Riavvia il ciclo con il nuovo | Vecchio comando **404**; nuovo **204** senza corpo. Non ricaricare prima di aver conservato quello nuovo |
| E17 | Ferma il ciclo; nel pannello **Heartbeat** premi **Revoke credential** e conferma. Riprova l’ultimo comando | **404**, **Credential: Not configured**. Metti in pausa il monitor con **Pause monitor → Confirm pause** per evitare altri incidenti |

Dopo E17 ferma il ciclo e metti in pausa il monitor, così non genera altri incidenti.

---

## Blocco F — Manutenzioni e quiet hours

**Preparazione:** parti da PROVA-HTTP **Up**, senza incidente aperto; usa **Ripristinare HTTP** se serve. Email prova e Webhook prova devono essere **Active**, **Policy prova** effettiva. F01–F06 si fanno nel dettaglio del monitor, F07 nel canale Email, F08–F10 nel dettaglio dell'incidente e in Mailtrap. Il Webhook deve restare senza quiet hours per fare da confronto. Prima di valutare un'email assente, controlla che il canale e il provider funzionino: un guasto di consegna rende la prova bloccata.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| F01 | Nel dettaglio PROVA-HTTP, sezione **Maintenance → Schedule maintenance**, lascia vuoto **Start (your local time)**, compila **End (your local time)** con data e ora fra almeno 15 minuti (giorno successivo se passi mezzanotte), **Reason** `Manutenzione privata di prova`, poi **Schedule maintenance** | Finestra nell’elenco corrente con stato **In progress**. Gli orari sono quelli del browser; annota l’ora di fine |
| F02 | Durante la finestra segui i passaggi di modifica URL di **Provocare il guasto HTTP**, ma attendi solo i check: non aspettarti un incidente durante la manutenzione | Monitor **Down** dopo check nuovi; nessun nuovo incidente né avviso di apertura per questo guasto. Controlla che la finestra non sia già finita |
| F03 | Nella riga della manutenzione attiva premi **End now** e conferma se richiesto. Lascia l’URL 503; attendi i check necessari alla soglia, ricaricando **Recent incidents** | Finestra conclusa e incidente aperto dopo la manutenzione. Usa le attese del tuo intervallo, non sempre 2–3 minuti |
| F04 | Segui **Ripristinare HTTP** e controlla l’incidente di F03 | **Up**, incidente **Resolved**, nessuna manutenzione attiva rimasta |
| F05 | In **Schedule maintenance** inserisci un inizio futuro fra 20 minuti e una fine fra 40 minuti, poi **Schedule maintenance**. Sulla nuova riga premi **Edit window**, sposta la fine di 5 minuti, **Save window**; infine **Delete window** e conferma | La modifica è visibile nella riga; dopo l’eliminazione quella finestra futura scompare |
| F06 | In **Schedule maintenance** imposta **Start (your local time)** a ieri e una fine futura valida, poi **Schedule maintenance** | Errore sul passato; nessuna nuova finestra salvata |
| F07 | In **Notifications**, riga **Email prova**, premi **Configure quiet hours**. Spunta **Enable daily quiet hours**; **Quiet hours start** qualche minuto prima di adesso, **Quiet hours end** abbastanza avanti da coprire apertura incidente e almeno 6 minuti successivi; **Quiet hours timezone** `Europe/Rome`, poi **Save quiet hours**. Ricarica | Riepilogo **Quiet hours daily: … (Europe/Rome)** con gli orari salvati. Con prove a 60 s puoi usare una fine fra 15 minuti; a 300 s scegli almeno 25 minuti |
| F08 | Durante la fascia Email appena salvata segui **Provocare il guasto HTTP**. Annota l'ora di conferma corrente (**Confirmed**, oppure ultima ora **Reopened**) e mantieni il guasto per almeno altri 6 minuti, sempre dentro la fascia | Nessuna email di apertura nella fascia; Webhook prova, senza quiet hours, riceve lo step dopo 5 minuti dalla conferma corrente |
| F09 | Nell’incidente F08 scorri a **Notification deliveries**: sulla riga Email controlla **Pending** in **Status** e il motivo in **Response**. Senza modificare l’incidente, attendi la fine esatta della fascia salvata e fino a un minuto per dispatcher e worker | Arriva l’avviso Email; la riga diventa **Sent**. Eventuali step maturati sul medesimo canale sono raggruppati; nessun nuovo cambio di stato richiesto |
| F10 | Prima **Ripristinare HTTP** e controlla il **Resolution summary** del primo incidente. Poi prepara una nuova fascia che copra apertura e recupero (almeno 15 min a 60 s, più lunga a 300 s), provoca un secondo guasto, verifica Email **Pending**, ripristina HTTP e attendi **Resolved** prima della fine. Dopo la fascia attendi un minuto. Infine in **Configure quiet hours** togli **Enable daily quiet hours** e premi **Save quiet hours** | Primo ciclo: riepilogo al canale già avvisato. Secondo ciclo: nessuna apertura tardiva né riepilogo Email per un incidente mai notificato a quel canale. Alla fine riepilogo **No quiet hours.**, HTTP operativo |

---

## Blocco G — Dashboard e report

Apri **Dashboard** (`/teams/{TEAM}/dashboard`).

**Preparazione:** usa **Gestione**, con lo stesso team dei monitor; ricarica per leggere dati correnti se il realtime è disattivato. Concludi il guasto di G02 con **Ripristinare HTTP** prima di passare a H, salvo che tu stia eseguendo anche G08–G10. Per i grafici seleziona un monitor con osservazioni recenti; un monitor nuovo non ha necessariamente dati nelle ore precedenti.

Per G03 resta nella Dashboard: nella tabella **Monitors**, **Charts** contiene **View charts for {nome monitor}** oppure **Selected**. Il nome del monitor apre invece il dettaglio completo, che ora include **Monitor charts** con selezione periodo/regione e tabelle equivalenti. Sono due ingressi validi: i vecchi link Dashboard restano compatibili.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| G01 | In Dashboard ricarica e guarda i riquadri **Up**, **Down**, **Unknown**, **Paused**, **Suspended**, **Open incidents**. In una seconda scheda apri **Monitors** dello stesso team, senza filtri, e confronta le righe di tutte le pagine | Conteggi coerenti. Pausa/sospensione e stato osservato sono informazioni diverse: usa anche la colonna **State**, non sommare categorie senza distinguerle |
| G02 | Se non c’è un incidente aperto, segui **Provocare il guasto HTTP**. Torna in Dashboard, ricarica e scorri alla tabella **Open incidents**; clicca il numero del relativo incidente | Riga del guasto HTTP; link al suo dettaglio nello stesso team |
| G03 | Nella tabella **Monitors** della **Dashboard**, colonna **Charts**, clicca **View charts for {nome monitor}**. Se compare **Selected**, il monitor è già selezionato. Scorri sotto la tabella | Resti su `/teams/{TEAM}/dashboard?monitor={MONITOR}` (con eventuale `page`). Sotto il nome selezionato compaiono **Last 24 hours · Hourly buckets · UTC**, **Response time** e **Observed uptime**. In assenza di misure compare il relativo messaggio di indisponibilità |
| G04 | Sotto i grafici apri **View response time data** e **View observed uptime data** | Valori coerenti con i grafici; gli intervalli senza dati restano mancanti |
| G05 | Sotto i grafici del monitor selezionato leggi **Observed uptime**, **Data coverage**, **SLA** e, se presenti, **Time up**, **Time down**, **Time unknown**, **Time excluded** | Nessun 100% inventato in assenza di misure. **SLA** può essere indisponibile con dati parziali. Annota i valori insieme al monitor e all’intervallo di 24 ore; l’assenza di durate non disponibili non equivale a zero |
| G06 | In Dashboard scorri a **Monthly reports** e trova **Monthly report**: scegli esplicitamente il mese corrente (il mese precedente è preselezionato), poi **Download PDF**. Apri il file scaricato dal browser | PDF `signalbox-report-…pdf`, leggibile, con mese e monitor del team scelto. Un report senza osservazioni deve dichiarare i dati mancanti |
| G07 | Nel PDF di G06 cerca `httpbin`, URL completi, email private e nomi dei monitor dell’altro team, se già noti. Confronta l’elenco monitor con quello del team corrente | Nessun URL riservato o dato estraneo. Non confondere il nome pubblico del team del report con una fuga di dati |

### Aggiornamenti in tempo reale (facoltativo)

Se scegli di eseguire G08–G10, prepara prima la connessione. Gli aggiornamenti automatici sono spenti con `BROADCAST_CONNECTION=log`; nella sessione precedente è stato usato `reverb`, ma va ricontrollato. In `.env` modifica l'unica riga `BROADCAST_CONNECTION` a `reverb`. Conserva i valori Reverb locali già configurati: non rigenerare chiavi. Servono ID, chiave e segreto non vuoti e la porta browser deve corrispondere a quella pubblicata da Compose. Per l'ambiente registrato era **18080**, non la porta interna **8080**. Controlla la mappatura in `docker compose ps reverb`; se mancano le credenziali o la configurazione non è chiara, segna il blocco facoltativo **BLOCCATO** e usa [SERVICES.md](SERVICES.md#reverb-listener-and-publishing) per la preparazione.

Dal **Terminale** avvia o ricrea i processi:

```sh
docker compose --profile realtime up -d reverb
docker compose exec web php artisan config:clear
docker compose up -d --no-deps --force-recreate web queue scheduler
```

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| G08 | Parti con HTTP operativo. Apri Dashboard in una scheda **Gestione**, ricaricala una volta e aspetta **Live updates connected.**. In un'altra scheda segui **Provocare il guasto HTTP**. Torna alla Dashboard e non ricaricarla | Stato del monitor e conteggio/incidente aperto si aggiornano da soli ai nuovi eventi. Se il banner non arriva a **Live updates connected.**, la prova è bloccata dalla connessione |
| G09 | Nella Dashboard apri strumenti sviluppatore → **Network → WS**, seleziona la connessione WebSocket e poi **Messages/Frames**; se necessario ricarica una volta per catturare la sottoscrizione | Frame di sottoscrizione a `private-team.{TEAM}`. Nella rete HTTP nessuna richiesta a intervalli fissi per ottenere aggiornamenti: recuperi iniziali, richieste dopo eventi e ping del WebSocket sono previsti e non sono polling |
| G10 | Lascia aperta Dashboard; da **Terminale** esegui `docker compose stop reverb`. Attendi **Connection lost. Reconnecting…**. Per verificare il recupero, ripristina HTTP in un'altra scheda e aspetta il check **Up** mentre Reverb è fermo; poi esegui `docker compose --profile realtime up -d reverb`, senza ricaricare la Dashboard | Pagina consultabile durante l'interruzione, poi **Live updates connected.** e stato HTTP recuperato a **Up**. Se resta in errore annota banner e richiesta fallita; non attribuire PASS per il solo riavvio del container |

---

## Blocco H — Status page

**Contesti:** **Gestione** con il tuo account e GiGa Team; **Pubblico** in finestra privata senza login; **Posta** in Mailtrap. Gestione e pagina pubblica sono due schermate diverse: modifica e salva nella prima, poi passa alla seconda e ricarica.

**Fixture storica:** la pagina esistente ha indirizzo `/status/status-test`. Alla ripresa usa il caso indicato nel registro corrente, senza ripartire automaticamente da H13 e senza creare una seconda pagina. Nei casi qui sotto `{SLUG}` vale `status-test`, salvo il cambio temporaneo di H16.

**Prima dei casi:** apri `/teams/2/status-pages` e cerca la riga della tua pagina. Deve contenere `API pubblica: PROVA-HTTP` e `Sito: PROVA-KEYWORD`, se hai usato i nomi proposti in H01. Se i nomi pubblici sono diversi annotali e segui quelli. Usa **Edit {titolo}** per controllare i monitor selezionati nei componenti; premi **Save page** solo se hai effettuato modifiche. Verifica che i due monitor siano **Active**, abbiano check recenti e che il piano ammetta gli intervalli. Per H13 evita altri guasti o manutenzioni sui componenti: altrimenti il risultato complessivo può essere diverso.

### H1. Creazione e bozza

Questi tre casi servono alla prima esecuzione. Per continuare da H13 riutilizza la pagina già salvata.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H01 | In **Gestione**, `/teams/{TEAM}/status-pages`, scorri a **Add a status page**. Compila **Title** `Stato Prova`, **Public address** `status-test` (solo il nome, senza `/status/`), **Color scheme** `dark`, **Accent** `amber`. Lascia vuoti descrizione e password, e non spuntare **Published: anyone with the address can reach this page**. Premi **Add component**: nel primo **Public name** scrivi `API pubblica` e in **Monitors in this component** seleziona solo `PROVA-HTTP`. Aggiungi un secondo componente `Sito` con solo `PROVA-KEYWORD`. Premi **Add status page** | Nell'elenco compare la pagina **Draft**, con i due componenti e i monitor corretti. Annota il suo titolo e `{SLUG}`. Se l'indirizzo è già occupato da un'altra pagina, scegline uno libero e usalo in tutte le prove |
| H02 | In **Pubblico** apri `/status/{SLUG}`, usando l'indirizzo appena annotato | HTTP **404**, senza titolo o componenti della pagina in bozza |
| H03 | In **Gestione** premi **Edit {titolo}** sulla pagina creata; spunta **Published: anyone with the address can reach this page** e premi **Save page**. Torna in **Pubblico** e ricarica `/status/{SLUG}` | La riga di gestione indica **Published**. La pagina pubblica mostra il titolo e i componenti |

### H2. Cosa vede il visitatore

**Preparazione:** pagina pubblicata e senza password. Per H06/H08 serve una nota pubblicata dell'incidente HTTP: se manca, segui **Aggiungere una nota privata o pubblicarla** nelle operazioni comuni usando `Stiamo verificando il problema`. La nota deve appartenere a un monitor incluso nella pagina. Mantieni anche una nota privata `Nota privata di prova` per il confronto.

Nella finestra **Pubblico**, la disposizione è questa:

1. Barretta decorativa, titolo e descrizione eventuale.
2. Frase di **stato generale**, subito sotto il titolo/descrizione.
3. Componenti: nome pubblico a sinistra, **stato del componente a destra**, poi 90 barrette dello storico e percentuale o messaggio di dati mancanti.
4. Modulo **Email updates**, poi elenco **Published incidents**. Questi ultimi mostrano soltanto note pubblicate; l'apertura di un incidente da sola non aggiunge un testo pubblico.

| Situazione | Frase sotto il titolo | Stato accanto al componente interessato |
|---|---|---|
| Tutti i componenti osservati sono operativi | **All systems operational** | **Operational** |
| Un componente è guasto e un altro non lo è | **Some systems are having problems** | **Outage** se tutti i suoi monitor osservati sono in incidente; **Partial outage** se solo alcuni lo sono |
| Tutti i componenti osservati sono in guasto completo | **Major outage** | **Outage** |
| Nessun guasto, ma almeno una manutenzione attiva | **Maintenance in progress** | **Under maintenance** per il componente in manutenzione |
| Nessun guasto/manutenzione, ma osservazioni mancanti o troppo vecchie | **Current status not fully known** | **Status unknown** per il componente senza osservazioni valide |

I monitor in pausa o sospesi non partecipano al calcolo. Un componente senza monitor attivamente osservati è **Status unknown**. Le frasi del riepilogo e le etichette dei componenti sono diverse: cerca quelle della colonna corretta.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H04 | In **Pubblico** ricarica `/status/{SLUG}`. Confronta titolo, frase sotto il titolo e nomi/stati dei due componenti con la gestione e la tabella precedente; scorri in fondo | Titolo corretto, stati coerenti e nota **History days are whole UTC days.** in fondo |
| H05 | Sotto ciascun componente guarda le barrette e il testo; passa il puntatore su una barretta per leggerne data e disponibilità | Senza misure nei giorni passati compare **No uptime data in the last 90 days.**; le giornate prive di dati non diventano 100%. Lo storico esclude oggi e usa giorni UTC completi; un guasto appena provocato modifica lo stato attuale, non la barretta di oggi |
| H06 | Scorri a **Published incidents** e cerca `Stiamo verificando il problema`; cerca anche `Nota privata di prova` nella pagina | La prima nota compare sotto il nome pubblico del componente, la seconda non compare |
| H07 | Sempre in **Pubblico**, apri il sorgente HTML come spiegato sotto e cerca uno per volta `PROVA-HTTP`, `httpbin`, `Nota privata`, `Causa privata`, `Manutenzione privata`, `GiGa Team`, la tua email, `team_id`, `monitor_id` | Nessuno di questi dati privati compare. Non cercare dalla finestra autenticata: quella può includere i dati dell'utente connesso |
| H08 | Nel medesimo sorgente cerca il titolo effettivo e `Stiamo verificando` | Entrambi presenti: questo controllo conferma che stai esaminando il documento giusto |
| H09 | In **Pubblico** disattiva JavaScript dagli strumenti del browser, ricarica e controlla il riepilogo; poi riattiva JavaScript e ricarica di nuovo | Con JS disattivato restano leggibili titolo, stato e voci pubblicate nel riepilogo server; moduli interattivi non richiesti in questa fase. Con JS riattivato torna la pagina completa |
| H10 | Con JavaScript riattivato, torna alla gestione **Status pages**, premi **Edit {titolo}**, scegli **Color scheme = light** e **Save page**. Ricarica la pagina pubblica nella finestra privata. Ripeti con **system** e tutti gli **Accent**, salvando ogni volta | Con **light** la pagina pubblica ha sfondo chiaro e testo scuro. Con **system** segue la preferenza chiaro/scuro del browser o sistema. Gli **Accent** cambiano la barretta decorativa; testo sempre leggibile |
| H11 | In **Pubblico** apri gli strumenti sviluppatore, attiva la barra dispositivi e imposta larghezza **390 px**. Scorri da titolo a **Published incidents**, poi esci dalla vista dispositivo | Testi, stati, barrette e modulo email leggibili; nessun controllo irraggiungibile fuori schermo |

Per aprire gli strumenti sviluppatore su Mac puoi usare **⌥⌘I** (Chrome/Firefox); su Windows/Linux **F12**. In Chrome, per il sorgente usa il menu della pagina **Visualizza sorgente pagina** o apri `view-source:http://localhost:8000/status/{SLUG}` nella stessa finestra privata. In Firefox usa **Visualizza sorgente pagina** dal menu contestuale. Cerca con **⌘F/Ctrl+F**. Per disattivare JavaScript in Chrome apri il menu comandi degli strumenti (**⇧⌘P/Ctrl+Shift+P**), cerca **Disable JavaScript**, eseguilo e ricarica; alla fine esegui **Enable JavaScript**. In altri browser usa l'opzione equivalente; annota il browser impiegato.

**H10 riguarda `/status/{SLUG}`**; il pannello di gestione resta scuro. Se **Color scheme** è `dark`, cambiare la preferenza del browser non schiarisce la pagina. Per provare la preferenza del browser salva prima `system`; in Chrome puoi cambiare l'emulazione in **More tools → Rendering → Emulate CSS media feature prefers-color-scheme**, poi provare `light` e `dark`. **Accent** modifica la barretta sopra il titolo. Per H11 attiva la barra dispositivi degli strumenti e imposta una larghezza di **390 px**; al termine esci dalla vista dispositivo.

Per H12 apri **Terminale** e usa il **Public address** effettivamente salvato: `{SLUG}=status-test` per la ripresa. La pagina deve essere pubblicata e senza password. Questo comando legge solo gli header, cioè le informazioni della risposta HTTP, senza effettuare login:

```sh
curl -sI 'http://localhost:8000/status/{SLUG}' | grep -iE '^HTTP/|cache-control|x-robots-tag|^link'
```

| Caso | Risultato atteso |
|---|---|
| H12 | `HTTP/… 200`, `Cache-Control` con `no-store` e `private`, `X-Robots-Tag: index, follow` e un `Link` con `rel="canonical"` verso l'indirizzo pubblico corrente |

Con **404** controlla prima l'indirizzo e la pubblicazione: una pagina inesistente o in bozza risponde correttamente con `noindex, nofollow, nosnippet`, senza canonical. Anche una pagina protetta da password usa correttamente `noindex` e non ha canonical, persino dopo lo sblocco; quel comportamento riguarda H18 e i casi successivi.

### H3. Aggiornamento e cache

**H13, procedura completa:**

1. In **Gestione** apri `/teams/2/status-pages`. Controlla che la pagina `status-test` sia **Published**, senza **Password protected**, e che `PROVA-HTTP` appartenga al componente `API pubblica` (o al nome pubblico che hai scelto).
2. In **Pubblico** apri `http://localhost:8000/status/status-test`. Annota la frase sotto il titolo e gli stati accanto ai componenti. Lascia aperta questa finestra.
3. In **Gestione** segui **Provocare il guasto HTTP** nelle operazioni comuni: **Monitors → PROVA-HTTP → Edit monitor → Replace URL**, URL `https://httpbin.org/status/503`, **Expected status = 200**, **Save changes**. Attendi i fallimenti necessari alla soglia.
4. Prima di giudicare la pagina pubblica, controlla che **Recent incidents** contenga un incidente aperto e annota l'ora di conferma del guasto corrente: **Confirmed**, oppure l'ultima ora in **Reopened** se si è riaperto lo stesso incidente. Il minuto di H13 parte da questa conferma corrente, non dal salvataggio dell'URL o dalla prima apertura storica. Il solo stato **Down** del monitor non basta.
5. Torna in **Pubblico** e **ricarica**. A destra di **API pubblica**, sopra le barrette, cerca **Outage**. Con un solo monitor in quel componente è questa l'etichetta attesa. Se hai assegnato più monitor allo stesso componente e solo alcuni sono guasti, leggi invece **Partial outage**.
6. Guarda anche **sotto il titolo/descrizione**: con `API pubblica` guasta e `Sito` operativo la frase è **Some systems are having problems**. Se tutti i componenti osservati sono in guasto completo è **Major outage**. In quel punto non appare l'etichetta isolata **Outage**.
7. Se non è cambiato subito, ricarica a intervalli di 10–15 secondi, fino a 60 secondi dalla conferma. Annota cosa compare e l'ora. Non serve pubblicare una nota per far cambiare lo stato; il testo in **Published incidents** si prova separatamente con H14. Lascia l'incidente aperto fino a H17.

**Se il risultato non arriva:** torna in **Gestione** e verifica piano/intervallo, **Active**, check recenti, manutenzione assente e incidente ancora aperto sul monitor assegnato. Se mancano questi prerequisiti il caso è **BLOCCATO**; se sono validi e la pagina ricaricata mostra ancora lo stato precedente oltre il minuto, registra **FAIL** con URL e testi dei due punti. La pagina pubblica richiede la ricarica in questi casi; non aspettare un aggiornamento automatico.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H13 | Esegui i sette passaggi sopra, mantenendo aperto l'incidente fino a H17 | **Outage** accanto al componente HTTP; **Some systems are having problems** sotto il titolo se l'altro componente è operativo. Con composizione diversa usa le regole sopra. Cambiamento visibile ricaricando entro 60 s dalla conferma |
| H14 | Nel dettaglio dell'incidente appena aperto aggiungi `Aggiornamento pubblico H14` e pubblicalo con la procedura comune (**Add update**, poi **Publish update** sulla nuova nota e **Confirm publication**). In **Pubblico** ricarica e scorri a **Published incidents** | Il nuovo testo compare già alla prima ricarica dopo il salvataggio riuscito, sotto il componente corretto; non serve attendere la scadenza della cache |
| H15 | In **Gestione → Status pages → Edit {titolo}** annota il titolo originale, cambia **Title** in `Stato Prova H15`, premi **Save page**. Ricarica **Pubblico** | Il nuovo titolo è visibile in cima già alla prima ricarica. Ripristina il titolo originale con **Edit Stato Prova H15 → Save page**, ricarica e verifica |
| H16 | In **Gestione → Edit {titolo}**, annota **Public address** originale (`status-test` nella ripresa). Cambialo in `status-test-h16`, premi **Save page**. In **Pubblico** visita sia `/status/status-test` sia `/status/status-test-h16`, adattando il primo se il tuo originale è diverso | Il vecchio indirizzo risponde **404**, il nuovo apre la stessa pagina. Poi rimetti **Public address** al valore originale, **Save page**: l'originale funziona e quello temporaneo dà 404. Da H17 usa di nuovo `{SLUG}` originale |
| H17 | In **Gestione** segui **Ripristinare HTTP**: URL `https://httpbin.org/status/200`, **Expected status = 200**, **Save changes**. Attendi **Up** e incidente **Resolved**. In **Pubblico** ricarica | Accanto al componente HTTP compare **Operational**; se anche gli altri componenti sono attivi e operativi, sotto il titolo compare **All systems operational**. Le note già pubblicate restano nello storico: non devono sparire per la sola risoluzione |

### H4. Password

**Preparazione:** usa l'indirizzo originale ripristinato in H16. La password di questa pagina è distinta da quella del tuo account. Scegli due password di prova diverse, entrambe di almeno 8 caratteri, e annotale privatamente. Ogni modifica si fa nella riga della pagina in **Gestione → Status pages → Edit {titolo}** e richiede **Save page**.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H18 | In **Gestione** inserisci la prima password in **Password (optional)**, lascia pubblicata la pagina e premi **Save page**. In **Pubblico** ricarica `/status/{SLUG}` | Compare **This status page is protected** con campo **Password** e pulsante **Open page**; titolo vero, componenti e note sono nascosti |
| H19 | Dalla pagina bloccata in **Pubblico** apri il sorgente HTML, cerca titolo originale, nomi componenti e testo H14; cerca anche `noindex` | Contenuti protetti assenti; metadati `noindex` presenti |
| H20 | Nella pagina bloccata inserisci una password errata nel campo **Password** e premi **Open page** | Messaggio di errore vicino al campo; nessun contenuto protetto visibile |
| H21 | Contando anche H20, effettua sei invii errati nello stesso minuto, sullo stesso indirizzo. Apri **Network** e osserva l'ultimo invio al percorso `/unlock` | Blocco temporaneo **429**; annota se compare una pagina, un messaggio o una finestra di errore. Non continuare a inviare |
| H22 | Attendi almeno 60 s dall'inizio della serie di tentativi, ricarica e inserisci la prima password corretta, poi **Open page** | Tornano titolo, componenti e contenuti pubblici; lo sblocco resta in questa sessione |
| H23 | In **Gestione → Edit {titolo}**, nel campo **New password** inserisci la seconda password e premi **Save page**. Ricarica la finestra **Pubblico**, precedentemente sbloccata | Torna la schermata protetta. La prima password è rifiutata; la seconda permette **Open page** |
| H24 | Nella finestra normale, dove sei autenticato come owner ma non hai sbloccato la pagina pubblica, apri `/status/{SLUG}` | Viene chiesta la password della pagina anche all'owner. Dopo il controllo torna alla gestione con l'indirizzo `/teams/{TEAM}/status-pages` |
| H25 | Lascia **Pubblico** sbloccato con la seconda password. In **Gestione → Edit {titolo}** togli la spunta **Published: anyone with the address can reach this page**, poi **Save page**. Ricarica **Pubblico** | **404** già alla prima ricarica, anche nella sessione che era sbloccata |
| H26 | In **Gestione → Edit {titolo}** rimetti la spunta **Published: anyone with the address can reach this page**, spunta **Remove the password** e premi **Save page**. Apri l'indirizzo in una sessione privata nuova, chiudendo prima tutte le precedenti finestre private se necessario | Titolo e componenti visibili senza password. **Remove the password** è una casella da spuntare, non un comando immediato: senza **Save page** non viene applicata |

### H5. Iscrizione email

**Preparazione:** pagina pubblicata e senza password dopo H26; Mailtrap aperto; scheduler e worker attivi. Usa `lettore@example.test` se non lo hai già iscritto a questa pagina; altrimenti scegli un nuovo indirizzo, per esempio `lettore-h5-02@example.test`, e sostituiscilo in tutti i casi H27–H37. Non serve una casella reale: la sandbox intercetta la posta.

Per H30–H36 puoi riutilizzare l'incidente risolto di H13: accetta note con stato **Resolved**. Non occorre provocare ogni volta un nuovo guasto. Aprilo in **Gestione**, poi usa la procedura comune di nota/pubblicazione. I testi diversi dei casi servono a riconoscere ogni invio. Prima di controllare l'assenza di una consegna, verifica che la nota sia stata davvero pubblicata e attendi almeno un minuto con scheduler/worker attivi e provider disponibile. Se la posta è bloccata non puoi concludere che il silenzio sia corretto.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H27 | In **Pubblico**, `/status/{SLUG}`, scorri a **Email updates**, inserisci il nuovo indirizzo in **Email address**, premi **Subscribe to updates** | Sopra il modulo: **If confirmation is needed, an email will arrive shortly. Check your inbox to confirm updates.** Non è ancora una conferma dell'iscrizione |
| H28 | In **Posta** cerca il destinatario scelto e la nuova email di conferma, attendendo fino a un minuto; aprila | Richiesta di confermare gli aggiornamenti senza titolo della pagina o dati del team. Conserva il collegamento privatamente per H29/H37 |
| H29 | Apri il link della nuova email nella finestra **Pubblico**, in una scheda separata. Non premere ancora il pulsante | Schermata **Confirm email updates** e pulsante omonimo; non deve ancora esserci il messaggio di successo. Aprire il link da solo non iscrive |
| H30 | In **Gestione** aggiungi e pubblica `Prova H30 prima della conferma`, con la procedura comune. Aspetta almeno un minuto e cerca in **Posta** quel testo per il destinatario scelto | Nessuna email di aggiornamento H30 a quel destinatario. L'email di conferma H28 rimane e non è un errore |
| H31 | Torna alla scheda del link H29 e premi **Confirm email updates** | **Your email is confirmed. You will receive future published updates.** |
| H32 | In **Gestione** aggiungi e pubblica `Prova H32 dopo la conferma`. In **Posta** attendi fino a un minuto e apri l'aggiornamento del destinatario scelto | Email con titolo della pagina, nome pubblico del componente, testo H32 e link di disiscrizione; senza nome del monitor, autore o note private. Se non arriva, diagnostica la consegna prima delle prove di assenza successive |
| H33 | In **Pubblico** torna a `/status/{SLUG}` e invia di nuovo lo stesso indirizzo con **Subscribe to updates**; controlla la posta per un minuto | Stesso messaggio generico di H27; nessuna seconda email di conferma per l'indirizzo già attivo |
| H34 | In **Gestione** aggiungi `Prova privata H34` con **Add update**. Verifica l'etichetta **Private** e non premere **Publish update**. Cerca il testo in **Pubblico** dopo ricarica e in **Posta** dopo un minuto | Il testo non compare né nella pagina pubblica né nelle email al lettore |
| H35 | Nell'email di aggiornamento H32 apri il collegamento di disiscrizione nella finestra **Pubblico**. Nella schermata **Unsubscribe from updates** premi il pulsante **Unsubscribe from updates** | Prima del pulsante è solo una richiesta di conferma; dopo compare **You are unsubscribed. No further updates will be scheduled.** |
| H36 | In **Gestione** aggiungi e pubblica `Prova H36 dopo disiscrizione`; verifica che il testo compaia nella pagina pubblica e controlla **Posta** per almeno un minuto | Nessuna email H36 al destinatario disiscritto, pur essendo la nota pubblica |
| H37 | Riprendi il collegamento originale di H28. Nella sua query modifica un solo carattere del valore `signature`, lasciando intatto il percorso e gli altri parametri, e aprilo | HTTP **403** per firma non valida; non è disponibile un pulsante che possa confermare l'iscrizione. Non incollare questo URL nel registro: contiene una capacità privata |

Il limite è di 5 richieste di iscrizione al minuto per IP: se lo raggiungi, attendi un minuto.

### H6. Dominio personalizzato

**H38–H42** provano il salvataggio e il rifiuto di un dominio non verificato in locale. In **Gestione → Status pages**, sotto la riga della pagina, trova **Custom domain**: è un modulo separato, non richiede di aprire **Edit {titolo}**. Non modificare un dominio reale già associato: annotalo e usa una pagina di prova separata se necessario e consentito dal piano.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H38 | In **Custom domain → Hostname** scrivi `status-h38.example.com`, poi **Save hostname**. È un sottodominio di esempio per questa prova negativa; non creare record DNS | Compaiono **Record name**, **Record value** e **Ownership not verified. This hostname does not serve the page.** |
| H39 | Nel campo **Hostname** prova uno per volta `https://x.com`, `127.0.0.1`, `*.example.com`, `localhost`, premendo **Save hostname** ogni volta | Messaggio di rifiuto per ogni valore, nessun dominio invalido salvato. Alla fine ricarica la gestione e controlla che sia ancora associato `status-h38.example.com` |
| H40 | Sul dominio salvato in H38 premi **Verify DNS ownership**, senza aver creato il record TXT | Verifica rifiutata, dominio ancora non verificato; nessun nome di team o pagina di altri clienti nel messaggio. Se compare 429, attendi la finestra di limitazione e ripeti |

**H41, Terminale:** esegui i tre comandi separatamente. Il primo invia un nome host non verificato; gli altri due interrogano il controllo interno per l'ammissione TLS, prima dall'esterno del container e poi dal suo interno. `{SLUG}` è sempre l'indirizzo originale della pagina, ma il nome host invalido deve essere rifiutato prima di servirla:

```sh
curl -i -H 'Host: status-h38.example.com' 'http://localhost:8000/status/{SLUG}'
curl -s -o /dev/null -w '%{http_code}\n' 'http://localhost:8000/internal/status-domains/allow?domain=status-h38.example.com'
docker compose exec web php -r '$h = @get_headers("http://127.0.0.1:8000/internal/status-domains/allow?domain=status-h38.example.com"); echo $h[0], PHP_EOL;'
```

| Caso | Risultato atteso |
|---|---|
| H41 | Il primo comando risponde **404**, senza header `Set-Cookie` né titolo/componenti della pagina. Il secondo restituisce **403**, il terzo **404**, con la configurazione locale predefinita dei peer TLS. Se hai modificato i peer autorizzati, annotalo prima di valutare questi ultimi due risultati |
| H42 | In **Gestione → Custom domain** premi **Remove hostname**, poi ricarica: il nome salvato e i dati **Record name / Record value** non devono più comparire. L'indirizzo principale `/status/{SLUG}` continua a funzionare |

**H43–H46 facoltativi, solo con un dominio che controlli.** La verifica completa richiede un record TXT nel DNS pubblico; cambiare soltanto `/etc/hosts` non prova la proprietà. Questi casi provano il routing HTTP locale, non l'emissione di un certificato HTTPS. TLS e deploy pubblico restano previsti in M12.

1. Sostituisci `status.tuodominio.it` con un tuo sottodominio disponibile e salvalo in **Hostname → Save hostname**.
2. Nel pannello DNS del tuo fornitore crea un record **TXT** con **Record name** e **Record value** mostrati da Signalbox. Se il fornitore aggiunge automaticamente il dominio di base al nome, non ripeterlo due volte. Mantieni quel record per tutta la prova.
3. Attendi la propagazione; torna in Signalbox e premi **Verify DNS ownership** finché compare **Ownership verified until …**. Fino ad allora H43–H46 sono **BLOCCATO**, non PASS.
4. Solo per indirizzare il tuo browser al server locale, aggiungi a `/etc/hosts` la riga `127.0.0.1 status.tuodominio.it`, con il dominio reale. La modifica al file di sistema può richiedere la password del computer; eseguila tu con il tuo editor. Non cambiare altre righe.
5. Tieni aperta la pagina principale `/status/{SLUG}` e una scheda separata con `http://status.tuodominio.it:8000/`. Il dominio personalizzato serve la pagina sulla radice `/`.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| H43 | In **Pubblico** apri `http://status.tuodominio.it:8000/`, sostituendo il dominio reale | Stessi titolo, componenti e note della pagina principale, serviti dalla radice |
| H44 | Sullo stesso dominio apri separatamente `/login`, `/teams` e `/status/altro-indirizzo` | **404** per tutti e tre; nessuna schermata privata di Signalbox |
| H45 | In **Gestione** imposta una password e salva come in H18. In **Pubblico** sblocca solo `/status/{SLUG}` sull'host principale e ricarica la radice del dominio: deve restare protetta. Cambia password e salva, sblocca solo il dominio e ricarica l'indirizzo principale: deve restare protetto | Lo sblocco vale soltanto per l'host usato. Per il secondo verso della prova il cambio password azzera lo sblocco precedente. Alla fine rimuovi la password con la casella **Remove the password** e **Save page** |
| H46 | In **Gestione → Custom domain** premi **Remove hostname**. Ricarica la radice del dominio, poi l'indirizzo principale | Il dominio risponde **404**, l'indirizzo principale funziona. Rimuovi soltanto la riga aggiunta a `/etc/hosts` e il record TXT di questa prova |

---

## Blocco I — Permessi e isolamento

**Preparazione, da completare prima di I01:**

1. Prepara gli account sintetici come in 1.4. In ciascun profilo apri `/account` e verifica l'email; poi `/teams` e scegli **Synthetic Alpha**. Qui **owner**, **admin**, **member**, **viewer** indicano rispettivamente `owner@signalbox.test`, `admin@signalbox.test`, `member@signalbox.test`, `viewer@signalbox.test`. Nessuno deve avere il ruolo operatore di piattaforma per I13.
2. Come **owner** annota gli ID dei due team, aprendo ciascuno da `/teams`. In Alpha crea un HTTP `PROVA-RUOLI-ALPHA`, in Beta un HTTP `PROVA-RUOLI-BETA`: URL `https://httpbin.org/status/200`, expected status `200`, intervallo `300`, timeout `5000`, soglia `2`, sola EU. Annota gli ID dai due dettagli. Il prefisso comune `PROVA-RUOLI-` serve per la ricerca, i suffissi permettono di distinguere i monitor nei selettori.
3. In Alpha crea una status page in bozza con indirizzo libero, per esempio `ruoli-alpha`, e un componente che include solo PROVA-RUOLI-ALPHA. Crea anche un canale Email di prova, per esempio `Email ruoli`, per verificare la lettura dei canali in I03. Non devi verificarlo o attivarlo se non provi invii.
4. Per I01 serve quota per **una seconda status page** in Alpha: trial Pro ancora valido oppure override **Granted Pro plan**, impostato dal tuo account operatore come in 1.5. Ricontrolla prima di I01: un rifiuto per quota non prova un errore del ruolo admin. Se non hai quota segna la parte di creazione pagina **BLOCCATO**, senza cancellare la pagina preesistente.
5. Come owner provoca un guasto sul solo monitor Alpha con l'URL `https://httpbin.org/status/503`, mantieni expected status `200`, salva e attendi l'incidente secondo intervallo/soglia. Annota il suo URL per I06. Nei profili dei ruoli copia questo stesso URL, senza cercare un incidente di GiGa Team a cui non appartengono.
6. In un profilo separato accedi a `tester@email.local`. Controlla `/teams`: non deve appartenere ad Alpha né a GiGa Team. Se è stato invitato nel frattempo, per I07–I09 serve un altro account verificato realmente estraneo: annota quello usato.

Al termine ripristina l'URL 200 del monitor Alpha, attendi la risoluzione e rimetti l'eventuale override di Alpha al valore annotato prima della prova. I cambi di ruolo degli inviti si fanno su un account nuovo in I14–I17, non sui quattro account di riferimento.

### I1. Ruoli

| Caso | Account | Cosa fare | Risultato atteso |
|---|---|---|---|
| I01 | admin | In Alpha apri il monitor di prova → **Edit monitor**, cambia **Name** temporaneamente e **Save changes**, poi ripristinalo. In `/teams/{ALPHA}/notifications` crea `Email admin prova` come D01. In `/teams/{ALPHA}/status-pages` crea una bozza `ruoli-admin` come H01, usando il monitor Alpha | Tutti e tre i salvataggi consentiti e visibili dopo ricarica. Se manca quota distingui il blocco dal rifiuto di autorizzazione |
| I02 | member | In Alpha apri il monitor → **Edit monitor**, cambia solo **Name** e **Save changes**, poi rimetti il nome originale. Nel dettaglio **Pause monitor → Confirm pause**, poi **Resume monitor → Confirm resume** | Modifica consentita; **Paused** e poi **Active**. Se la pausa interrompe la preparazione dell'incidente, attendi nuovi check e verifica che l'URL copiato per I06 resti valido |
| I03 | member | Apri `/teams/{ALPHA}/notifications` e `/teams/{ALPHA}/status-pages`; confronta canali e pagine con quanto preparato dall'owner | Dati leggibili, destinazioni dei canali mascherate; nessun controllo per aggiungere/modificare/eliminare canali, policy o pagine |
| I04 | viewer | Apri `/teams/{ALPHA}/monitors`, poi clicca PROVA-RUOLI-ALPHA | Lista e dettaglio leggibili; assenti **New monitor**, **Edit monitor**, **Pause monitor**, **Resume monitor**, **Delete monitor** |
| I05 | viewer | Incolla nella barra indirizzi `/teams/{ALPHA}/monitors/create`, poi `/teams/{ALPHA}/monitors/{MONITOR_ALPHA}/edit`, usando gli ID annotati | **403** su entrambi, nessun modulo modificabile. Torna alla lista tramite indirizzo |
| I06 | viewer | Apri l'URL dell'incidente Alpha annotato nella preparazione e scorri dopo la Timeline e il registro consegne | Lettura consentita; **Your role has read-only access to incidents.**; nessun **Take responsibility**, **Add update**, **Publish update** o salvataggio severità/causa |

### I2. Isolamento tra team

| Caso | Account | Cosa fare | Risultato atteso |
|---|---|---|---|
| I07 | account estraneo | Incolla separatamente `/teams/{ALPHA}/monitors`, `/teams/{ALPHA}/dashboard`, `/teams/{ALPHA}/monitors/{MONITOR_ALPHA}` | **404** su tutte le pagine, senza nomi o dati di Alpha |
| I08 | account estraneo | Apri gli URL privati copiati dalla gestione di GiGa Team: `/teams/2/incidents/{INCIDENT}`, `/teams/2/notifications`, `/teams/2/status-pages` | **404** su tutti. Qui provi la gestione privata: la pagina `/status/{SLUG}` pubblicata è invece accessibile intenzionalmente |
| I09 | account estraneo | Apri `/teams/2/reports/2026-11`, oppure cambia il mese al mese corrente mantenendo il team estraneo | **404**, nessun PDF scaricato |
| I10 | owner | In `/teams/{ALPHA}/monitors/{MONITOR_ALPHA}` sostituisci solo il numero del monitor con `{MONITOR_BETA}` lasciando il numero di Alpha | **404** anche se l'owner appartiene a entrambi i team. Il monitor Beta resta accessibile dal suo percorso sotto Beta |
| I11 | owner | In **Monitors** di Alpha cerca `PROVA-RUOLI-` e premi **Apply filters**; annota le righe. Vai a `/teams`, scegli Synthetic Beta nel campo **Team**, premi **Switch team**, poi apri **Monitors** e ripeti la ricerca | Alpha mostra solo PROVA-RUOLI-ALPHA; Beta solo PROVA-RUOLI-BETA. Il nome del team e il numero nel percorso devono coincidere con quello scelto |
| I12 | owner | Apri `/teams/{ALPHA}/status-pages`, **Edit {titolo della pagina ruoli-alpha}**, scorri a **Monitors in this component** | PROVA-RUOLI-ALPHA selezionabile; PROVA-RUOLI-BETA assente. Premi **Close editor** senza modifiche |
| I13 | owner, admin, member | In ciascuno dei tre profili sintetici apri `/admin` e `/horizon` | `/admin` risponde **404**, `/horizon` **403**; nessun elenco globale team o pannello code. Se uno è operatore, il prerequisito non è valido e va usato un account senza quel privilegio |

### I3. Inviti e membri

**Preparazione:** torna alla finestra **Gestione**, tuo account owner di GiGa Team, `/teams/2`. Usa un destinatario nuovo, per esempio `nuovo-h14-02@example.test`, e un profilo invitato dedicato senza login. Qui l'accesso all'email verificata è parte della prova: il solo link d'invito o la sola registrazione non aggiungono il membro.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| I14 | In **Invite a member** compila **Invitation email** col nuovo indirizzo, **Invitation role = viewer**, poi **Send invitation** | Nuovo invito nell'elenco **Invitations**, nuova email allo stesso destinatario in Mailtrap |
| I15 | Nel profilo invitato apri il link Mailtrap; premi **Create an account** e compila il modulo col medesimo indirizzo, password e conferma, poi **Create account**. Verifica l'email aprendo il nuovo link di verifica nello stesso profilo. Torna a **Review invitation** da `/account`, oppure `/invitations/accept`, e premi **Accept invitation** | Solo dopo **Accept invitation** compare **You have joined the team.** e il team è accessibile con ruolo **viewer**. La registrazione da invito non crea un team personale |
| I16 | Come owner in `/teams/2`, in **Members** trova l'account nuovo: scegli **member** in **Role for {nome}**, premi **Save role for {nome}**. Nel profilo invitato ricarica Monitors e controlla che possa modificare. Poi come owner premi **Remove {nome}** sulla stessa riga e conferma; nel profilo invitato ricarica `/teams/2/monitors` | Cambio ruolo applicato; dopo la rimozione la pagina del team restituisce **404**, non dati rimasti visibili dopo ricarica |
| I17 | Come owner invia un altro invito a un nuovo indirizzo. In **Invitations** premi **Revoke invitation for {email}** e conferma. Apri il suo link Mailtrap in un profilo separato | **This invitation is unavailable or expired. Ask a team owner or admin for a new link.**; nessun pulsante di accettazione valido |
| I18 | Come owner sintetico, in `/teams` scegli Synthetic Alpha nel campo **Team** e premi **Switch team**; controlla dashboard/nome/URL, apri **Monitors**; ripeti scegliendo Synthetic Beta | Preferenza aggiornata soltanto dopo conferma server e dashboard del team scelto; nome e ID nel percorso coerenti. Il link col nome in `/teams` apre la dashboard; **Team** nel menu comune apre membri/inviti |
| I19 | Come owner principale di GiGa Team apri `/teams/2` e guarda i controlli in fondo | **Leave team** assente; presente **Transfer primary ownership**. Non trasferire la proprietà per questa prova |

---

## Blocco J — Limiti di piano

**Preparazione:** per J01 usa GiGa Team con Pro effettivo (**Granted Pro plan** se il trial è scaduto), minimo visibile `1 min`; **Unlimited testing** non è adatto perché consente intervalli più brevi. Per J02–J06 usa `tester@email.local` in **Tester Team**, senza override, con trial scaduto e minimo `5 min`. Annota il suo numero come `{TEAM_FREE}`. Non usare `2` se quel team ha ancora l'override Pro del collaudo.

Conta tutti i monitor del team Free, incluse le pagine successive della lista e quelli in pausa. I nuovi monitor di questa sezione si chiamano `PROVA-LIMITE-01`, `…02`, ecc., HTTP, URL `https://example.com`, expected status 200, intervallo 300, timeout 5000, soglia 2, sola EU. Mettili subito in pausa se non servono check. Per la prova di cancellazione usa soltanto uno di questi monitor temporanei: non eliminare dati preesistenti per raggiungere una quota. Se non c'è alcun monitor sacrificabile e non puoi crearne uno, J05 resta **BLOCCATO**.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| J01 | Come owner di GiGa Team con Pro, apri un monitor di prova → **Edit monitor**, imposta **Interval (seconds)** `30`, poi **Save changes** | Già durante la digitazione compare sotto **Interval (seconds)** **Your plan requires an interval of at least 60 seconds.** Premendo Invio o **Save changes** non partono richieste né navigazioni: il modulo conserva `30` e gli altri campi. Correggendo a `60` l’errore sparisce subito e l’invio è consentito. Il valore persistito resta invariato finché non salvi un intervallo valido |
| J02 | Come tester in Tester Team Free, apri un monitor temporaneo → **Edit monitor**, imposta **Interval (seconds)** `60` e **Save changes**. Se non hai monitor, usa un modulo **New monitor** valido con quel solo valore errato | Rifiuto, minimo 300; nessun valore invalido salvato. Ripristina 300 prima di continuare |
| J03 | Crea solo i monitor temporanei necessari per arrivare a **5** totali. Prima di salvare il quinto lascia aperto un altro modulo **New monitor** valido in una seconda scheda. Dopo il quinto, ricarica la lista e invia dal modulo rimasto aperto. Se parti già a quota apri direttamente `/teams/{TEAM_FREE}/monitors/create`, compila un HTTP temporaneo valido e premi **Create monitor** | Lista **5 of 5 monitors used**, senza **New monitor**, avviso di quota. Il percorso diretto di creazione resta accessibile all'owner e mostra **Your plan allows 5 monitors and all are in use. Saving will be refused until you delete a monitor or upgrade.**; è il salvataggio che deve essere rifiutato, senza creare un sesto monitor |
| J04 | Metti in pausa uno dei cinque, poi ricarica lista e pagina di creazione | Sempre quota piena e creazione rifiutata; **Paused** non libera posti |
| J05 | Elimina e conferma soltanto un monitor `PROVA-LIMITE-…` creato per questa sezione. Ricarica la lista, poi **New monitor** e crea un nuovo temporaneo con intervallo 300 | Posti usati da 5 a 4; creazione consentita; di nuovo 5 totali. Conserva o elimina solo i dati temporanei al termine |
| J06 | In **Status pages** del team Free crea una prima bozza di prova se non esiste nessuna pagina; poi prova una seconda bozza, con titolo e indirizzo diversi | Seconda creazione rifiutata o modulo non offerto con messaggio di quota. Le bozze contano nel limite di una pagina. Non eliminare una pagina preesistente |

La sospensione dei monitor oltre quota dopo un downgrade arriva con M9.05, quindi non è da provare adesso.

Al termine annota quali monitor e bozze hai creato qui; la pulizia finale deve riguardare solo quelli di prova.

---

## Blocco K — Pannello amministratore

**Preparazione:** serve il tuo account verificato e la sua password. Annota prima se è già operatore e se ha già la 2FA: non disattivare una protezione esistente per forzare K01. Per K02 serve anche un'app autenticatore se devi attivarla. Il ruolo owner/admin di un team non abilita questo pannello.

**Regola da verificare:** in questo ambiente locale (`APP_ENV=local`) la 2FA è **facoltativa** per l'operatore; in produzione è **obbligatoria**. Leggi solo la riga APP_ENV del tuo `.env` per sapere quale esito aspettarti. Non cambiarla per il collaudo e non avviare un ambiente di produzione per K01. Questa distinzione è implementata nella regola di accesso descritta in [PLATFORM-ADMIN.md](PLATFORM-ADMIN.md#access-rule).

Dal **Terminale** elenca gli operatori e, solo se necessario, promuovi l'account che userai. Sostituisci `{EMAIL_OPERATORE}` con la sua email, mantenendo le virgolette. La promozione chiede conferma; eseguila tu. Annota se hai aggiunto il ruolo in questa prova per sapere se rimuoverlo alla fine.

```sh
docker compose exec web php artisan signalbox:platform-admin --list
docker compose exec web php artisan signalbox:platform-admin '{EMAIL_OPERATORE}'
```

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| K01 | Se l'account operatore non ha ancora 2FA, accedi in **Gestione** e apri `/admin`. Se ha già 2FA, registra questo caso **NON ESEGUITO: 2FA già presente**, senza disattivarla | **In locale:** elenco team accessibile anche senza 2FA, comportamento previsto. **Solo in un ambiente già di produzione:** rimando a `/account/security` e **Enable two-factor authentication to use the admin panel.**; quel ramo non è verificato dal collaudo locale |
| K02 | Per provare anche l'accesso con 2FA, attivala e confermala con A08 sul tuo account operatore, conservando privatamente i recovery code; se è già attiva riutilizzala. Riapri `/admin` | Elenco globale dei team; aprendo il nome di uno accedi al suo dettaglio amministrativo. In locale attivare la 2FA non è un prerequisito per K03–K05 |
| K03 | Apri un team sintetico e annota override/scadenza iniziali. In **Plan override** scegli **Unlimited testing**, compila **Reason (required, recorded in the audit log)** con `Collaudo limiti temporanei`, poi **Save override** e accetta il dialogo nativo. Se compare la richiesta password nella stessa pagina, compila **Current password** e premi **Confirm password and save override**: l’operazione viene eseguita dopo la conferma, senza ripetere il salvataggio. Con un account membro di quel team apri Monitors e modifica un monitor temporaneo a 30 s | Ritorno allo stesso dettaglio amministrativo del team con **Plan override saved.**, anche dopo aver mostrato QR/codici 2FA in K02; nessuna schermata JSON di Inertia. Limite minimo 30 s nella lista e salvataggio a 30 s consentito. Un operatore che non è membro del team non ottiene per questo accesso alle sue pagine private |
| K04 | Prima rimetti il monitor temporaneo a 300 s e salva. Nel pannello del team scegli **No override (stored plan and trial apply)**, compila un motivo e premi **Save override**, accettando il dialogo nativo. Se compare la conferma inline, inserisci **Current password** e premi **Confirm password and save override**; una password errata deve lasciare invariati override, scelta e motivo | Ritorno allo stesso dettaglio amministrativo con **Plan override removed.**, limiti del piano/trial effettivo ripristinati. Se prima di K03 c'era già un altro override, ripristina anche quello con motivo e scadenza annotati; non lasciare il team con una configurazione diversa senza volerlo |
| K05 | Col medesimo account operatore verificato (2FA richiesta in produzione), apri `/horizon` e guarda supervisori/processi | Pannello accessibile, Horizon attivo e worker visibili. Un pannello accessibile ma senza worker non prova l'esecuzione dei check: torna a 1.1 |

Alla fine, se hai aggiunto il ruolo solo per questa prova e non ti serve più, revocalo dal terminale. Non togliere un ruolo che esisteva già senza averlo deciso:

```sh
docker compose exec web php artisan signalbox:platform-admin '{EMAIL_OPERATORE}' --revoke
```

---

## Blocco L — Accessibilità e usabilità

**Esecuzione corrente in UI.06.3:** il rinvio deciso il 2026-11-09 è terminato con l’implementazione della fase A. Usare i passi aggiornati sotto e registrare ogni limite senza promuovere gli esiti precedenti. Piano in [TASKS.md](TASKS.md), brief in [USER-DASHBOARD.md](USER-DASHBOARD.md).

**Preparazione:** esegui le prove su **Dashboard**, **Monitors**, dettaglio monitor e relativi grafici/tabelle, dettaglio incidente, **Maintenance**, **Notifications**, **Notification deliveries**, gestione **Status pages**, **Team**, account e pagina pubblica. Includi menu comune, cambio team, Account menu e dialogo mobile. Apri gli editor e le sezioni View … data con la tastiera per coprire anche controlli inizialmente nascosti. Per le pagine private usa un account con permessi di modifica; per quella pubblica il contesto anonimo. Annota browser/versione. Usa risorse e testi temporanei; se riusi l'incidente risolto di H13, aggiungi note con **Status after this update = Resolved**.

Per L02 e lo zoom nativo di L03 segui anche [BROWSER-NATIVE-CHECKS.md](BROWSER-NATIVE-CHECKS.md), che elenca i due moduli e le schermate da osservare.

Per vista mobile e rete usa gli strumenti sviluppatore: **Network** per **Slow 4G / Offline**, barra dispositivi per larghezza 390 px. I nomi delle opzioni variano fra browser: annota l'equivalente usato. **L02 richiede una tecnologia assistiva per valutare l'annuncio**: su Mac VoiceOver si attiva con ⌘F5 (o Fn+⌘F5); senza lettore di schermo puoi confermare solo l'errore visibile, lasciando l'annuncio non verificato.

| Caso | Cosa fare | Risultato atteso |
|---|---|---|
| L01 | Per ciascuna schermata usa solo Tab/Shift+Tab per muoverti, Invio per link/pulsanti, Spazio per le caselle e frecce per i menu. Esegui un filtro e apri/chiudi un editor senza mouse | Focus riconoscibile, ordine sensato, tutti i controlli raggiungibili e attivabili. Su Safari abilita prima la navigazione da tastiera dei controlli nelle preferenze |
| L02 | Con lettore di schermo attivo, apri **New monitor**, compila un HTTP valido e svuota solo **Name**, poi **Create monitor**. Ripeti su un altro modulo, per esempio password pubblica errata | Errore visibile vicino al campo, annunciato dalla tecnologia assistiva; nessuna risorsa invalida creata. Registra separatamente il risultato visivo e l'annuncio |
| L03 | Su ciascuna schermata prova prima larghezza 390 px, poi finestra normale con zoom browser 200%; scorri in verticale e, dove previsto, nelle tabelle in orizzontale | Pulsanti e messaggi raggiungibili, testo leggibile, nessun controllo irraggiungibile fuori schermo. Alla fine ripristina zoom 100% e disattiva vista dispositivi |
| L04 | In **Network** scegli **Slow 4G**. Nell’incidente di prova già risolto lascia **Status after this update = Resolved** e aggiungi una nota univoca `Prova doppio invio L04 {browser/data}` e fai doppio clic su **Add update** | Durante l'invio compare **Saving…**, pulsante disabilitato; una sola nota con quel testo in Timeline dopo ricarica. Poi rimetti **No throttling** |
| L05 | Apri l'incidente mentre sei Online; passa a **Offline**, tenta **Add update** con `Prova offline L05` e annota messaggio/stato del pulsante. Torna Online e usa **Review saved data (new tab)** per verificare la Timeline prima di riprovare una volta se la nota manca | **Outcome not confirmed**, bozza conservata e invio bloccato; **Review saved data (new tab)** permette la verifica senza perdere la bozza. Dopo il controllo usa **I checked the saved data** e invia una volta soltanto se assente; nessun replay al ritorno online né duplicato. Se devi ricaricare per uscire da un blocco, annotalo come comportamento osservato, non come prova superata |
| L06 | Online, salva una nota privata univoca, usa **Back to incidents**, poi Indietro/Avanti e reload. Ripeti lasciando una bozza: **Stay** la conserva e **Discard and leave** permette l’uscita | Navigazione coerente; nota presente una volta sola, nessun nuovo invio dovuto alla ricarica |
| L07 | Apri **Console** prima delle azioni; distingui gli errori precedenti da quelli dell'azione in corso. Per un errore annota caso, URL, testo e ora | Nessun errore JS inatteso. 403/404/429 provocati nei relativi casi e l'interruzione intenzionale di L05 sono attesi, ma un'eccezione JS non gestita va comunque registrata |
| L08 | In un secondo browser annota nome/versione; ripeti login, filtro monitor, modifica di un nome con ripristino, lettura incidente, pagina pubblica e iscrizione con un nuovo indirizzo sintetico. Se hai scelto il realtime, prova anche connessione e recupero | Stessi risultati osservabili; registra le differenze con il relativo caso originale. Al termine ripristina nomi e annulla l'iscrizione sintetica se confermata |

---

## Se qualcosa non parte

**Come usare questa sezione:** fermati sul caso corrente, annota cosa manca e controlla prima l'interfaccia. I comandi qui sotto sono diagnostici salvo quelli esplicitamente indicati come riavvio, build o generazione dati. Non occorre leggere il database per eseguire il collaudo ordinario.

**I monitor restano Unknown o Last check non avanza:** in **Monitors** controlla **State = Active**, intervallo consentito dal riepilogo del piano e **Last check**; nel dettaglio controlla **Next check**. Poi dal terminale:

```sh
docker compose exec web php artisan config:show monitor-scheduling.enabled
docker compose exec web php artisan horizon:status
docker compose logs --tail=30 scheduler
docker compose logs --tail=50 queue
```

Il primo deve mostrare `true`, il secondo deve confermare Horizon attivo. Negli altri due guarda gli orari e gli eventuali errori recenti, senza condividere log con credenziali. Non c'è un pulsante per forzare un controllo: si attende il prossimo intervallo.

Se hai appena modificato `.env`, un valore corretto letto da terminale non dimostra che i processi già avviati lo abbiano caricato. Dopo aver controllato il piano, per ricaricare la configurazione e ricreare i processi usa:

```sh
docker compose exec web php artisan config:clear
docker compose up -d --no-deps --force-recreate web queue scheduler
```

Poi attendi un intervallo completo e ricontrolla **Last check**. Un job molto breve nei log da solo non dimostra la causa del blocco: annota l'errore effettivo, se presente.

**Solo alcuni monitor non ricevono nuovi check:** controlla il piano come in [1.5](#15-attenzione-ai-trial). Un monitor a 60 s non è ammesso da Free (minimo 300 s), anche se Horizon è attivo e **State** è **Active**. Può restare con **Next check** nel passato; il riavvio non risolve la quota. Applica l'override Pro dalla procedura 1.5 oppure modifica **Interval (seconds)** a `300` e **Save changes**. Per un incidente servono tanti fallimenti consecutivi quanti indicati in **Failures before alerting**, in tutte le regioni configurate.

**H13: non trovo Outage:** apri `/status/{SLUG}` in **Pubblico**, guarda a destra del nome del componente sopra le barrette. Sotto il titolo cerca invece **Some systems are having problems** o **Major outage**. Prima controlla l'incidente aperto del monitor incluso, poi ricarica entro un minuto dalla conferma corrente. Segui la [procedura H13](#h3-aggiornamento-e-cache), che distingue anche il caso di un incidente riaperto. Se il componente è **Status unknown**, verifica che ci sia almeno un monitor attivo e con check recenti; se è **Under maintenance**, controlla la sua manutenzione.

**Lo stato cambia solo ricaricando:** con `BROADCAST_CONNECTION=log` gli aggiornamenti automatici sono disattivati. Ricarica dopo i check oppure segui [Aggiornamenti in tempo reale](#aggiornamenti-in-tempo-reale-facoltativo). Cambiare manualmente lo stato dell'incidente non cambia lo stato `up`/`down` del monitor, che dipende dai risultati dei check.

**Vite mostra `Failed to resolve import "laravel-echo"`:** se hai avviato `npm run dev` sul computer, le dipendenze devono essere installate anche sul computer. Il servizio Node di Docker usa un volume diverso: la sua installazione non aggiorna il `node_modules` locale. Ferma Vite con Ctrl+C nel suo terminale, poi dalla cartella del progetto esegui:

```sh
npm ci --ignore-scripts --strict-peer-deps
npm run dev
```

Ricarica la pagina e riprendi il caso interrotto. Echo/Pusher sono già dichiarati nel lockfile: non aggiungere nuove versioni per aggirare l’errore. Se il problema persiste, annota il modulo non trovato e l’indirizzo di Vite mostrato nel terminale. Dettagli in [BOOTSTRAP.md](BOOTSTRAP.md#host-vite-and-docker-dependencies).

**Dopo Save override viene chiesta la password ma il piano resta invariato:** prima di BT.14 il redirect di riconferma interrompeva il salvataggio. Con il codice aggiornato la password si conferma nella stessa pagina del team: **Current password** → **Confirm password and save override**. La password valida fa partire una sola volta l’operazione scelta; una password errata o **Cancel** non cambiano l’override e conservano il motivo. Ricarica una volta le schede aperte per caricare la nuova UI. Verifica l’esito con **Plan override removed.**/**Plan override saved.**, **Plan override** e **History**.

**Dopo Save override compare un JSON del QR 2FA:** difetto di redirect corretto in BT.13. Riapri direttamente `/admin/teams/{TEAM}` e ricarica la pagina per usare il codice aggiornato; verifica **Plan override** e **History**, perché il salvataggio può essere già riuscito prima del redirect errato. Prosegui K03 se l’override desiderato è già presente. Il salvataggio e la rimozione ora tornano esplicitamente al dettaglio del team; non modificare la 2FA per risolvere questo errore. Non inserire QR, chiavi o codici nel registro.

**Il link locale non si apre in Brave con `ERR_SOCKS_CONNECTION_FAILED`:** usa una finestra privata standard per il profilo dell’invitato. La finestra privata con Tor usa un proxy; durante il collaudo l’utente ha confermato che era la causa e che il collegamento funzionava in Firefox. Mantieni il link originale completo; il solo errore di rete non prova che l’invito sia invalido.

**Reverb attivo ma compare “Live updates could not refresh”:** è stato corretto un errore del trasporto browser che impediva di inviare le richieste di aggiornamento nonostante la sottoscrizione riuscita. Con i sorgenti aggiornati ricompila gli asset (`docker compose run --rm node npm run build`), poi ricarica completamente la pagina. Il messaggio atteso è **Live updates connected.**; lascia aperta la lista mentre un nuovo check cambia lo stato del monitor e verifica l'aggiornamento senza ricaricare. Se l'errore persiste, annota lo stato HTTP della richiesta in Network e gli eventuali errori Console, senza condividere cookie o header di autorizzazione. Dettagli: [REALTIME-CLIENT.md](REALTIME-CLIENT.md#native-browser-fetch-context-correction-2026-11-06).

**Solo per una diagnosi aggiuntiva, stato dei monitor nel database:** il comando seguente legge i campi operativi senza modificarli. L'output può contenere nomi privati: non pubblicarlo integralmente.

```sh
docker compose exec postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT id, team_id, name, is_paused, last_status, last_checked_at, next_check_at FROM monitors ORDER BY id;"'
```

**Le email non arrivano:** controlla prima destinatario e ora in Mailtrap. Per gli avvisi del team apri l'incidente → **Notification deliveries**: **Pending** con motivo di quiet hours è un rinvio previsto; **Failed** richiede diagnosi. Per il lettore della status page verifica che abbia premuto **Confirm email updates** e che la nota sia **Published**, non solo **Private**. Poi controlla scheduler/queue e i soli campi non segreti del trasporto email:

```sh
docker compose logs --tail=20 scheduler | grep -E 'dispatch'
docker compose logs --tail=20 queue
grep -E '^MAIL_(MAILER|HOST|PORT)=' .env
```

Se il provider rifiuta per quota o credenziali, segna la consegna **BLOCCATO** finché non è risolto. Svuotare la casella non garantisce il rinnovo della quota di invio. Non interpretare un'email assente come PASS nelle prove di silenzio con un trasporto non funzionante.

**Pagina bianca o errore sui file della pagina:** ricompila dal terminale, poi ricarica completamente il browser (Chrome/Firefox: ⇧⌘R su Mac, Ctrl+Shift+R su Windows/Linux). Se persiste, annota il primo errore della Console:

```sh
docker compose run --rm node npm run build
```

**Errore 500:** cerca l'errore in `storage/logs/laravel.log` e riportami l'ultima eccezione, dopo aver controllato che non contenga password o token.

**Vedere lo storico a 90 giorni con dati:** le barrette usano riepiloghi dei giorni UTC già conclusi. Con soli check di oggi è corretto non avere storico: non cambiare l'orologio o le date per questa prova. Il comando seguente **scrive/aggiorna i riepiloghi nel database di sviluppo**, non è una lettura; usalo solo se vuoi generarli dai check già esistenti. Non crea dati sintetici dei giorni precedenti e non rende oggi un giorno concluso:

```sh
docker compose exec web php artisan metrics:rollups
```

---

## Pulizia finale

**Eseguila quando hai finito o vuoi fermare il monitoraggio.** Se rimandi i casi successivi, conserva pagina, monitor, note e identificativi per riprenderli: non cancellare i dati necessari a H13–H46. Prima di fermare i processi registra il prossimo caso e l'ultimo esito osservato.

1. Ferma eventuali cicli heartbeat con Ctrl+C nel loro terminale.
2. Ripristina HTTP a URL 200 e attendi il check **Up**/incidente **Resolved**, se vuoi lasciare i dati operativi. In **Status pages** ripristina titolo, indirizzo originale e visibilità concordata; rimuovi soltanto password e domini aggiunti per la prova. Disattiva le quiet hours di prova con **Save quiet hours**. Disiscrivi i lettori sintetici confermati.
3. Prima di rimuovere il ruolo operatore, rimetti gli override temporanei alla configurazione iniziale annotata. Se vuoi continuare le prove a 60 s in un'altra sessione, annota esplicitamente quale override lasci attivo e perché.
4. Metti in pausa i monitor sintetici se vuoi conservarli. Elimina solo i temporanei che hai deciso di scartare (B14, I, J), usando il loro team e nome; non cancellare tutti i monitor di un team o una risorsa preesistente solo perché ha un prefisso simile.
5. Se vuoi fermare tutti i check di questo ambiente, imposta l'unica riga `MONITOR_SCHEDULING_ENABLED=false` in `.env`. Se il realtime era stato acceso solo per il collaudo, rimetti `BROADCAST_CONNECTION=log`. Poi ricrea i processi perché leggano questi valori:

   ```sh
   docker compose exec web php artisan config:clear
   docker compose up -d --no-deps --force-recreate web queue scheduler
   docker compose exec web php artisan config:show monitor-scheduling.enabled
   ```

   L'ultimo comando deve mostrare `false`. Se hai disabilitato Reverb solo per chiudere questa prova, ferma anche il listener con `docker compose stop reverb`; se lo usavi già prima, conserva la configurazione iniziale che hai annotato.

6. Revoca il ruolo operatore soltanto se aggiunto per la prova e non più necessario, con il comando del blocco K. Non disattivare la 2FA preesistente per la pulizia.
7. Rimuovi le sole righe `/etc/hosts` e i record TXT aggiunti in H43, dopo aver rimosso il dominio da Signalbox. Elimina i canali temporanei verso webhook.site se non ti servono più e svuota gli appunti con token/codici.
8. Non usare `migrate:fresh` o comandi di rimozione dei volumi Docker: cancellerebbero i dati che devi conservare.

---

## Registro degli esiti

Ultima sessione: **2026-11-09** · Browser: **Brave / Chromium 157.0.8103.39** · Secondo browser: **Firefox 159.0**

**Ripartenza corrente: UI.06.3 / L**, secondo lo stato nel piano. I risultati osservati dall’agente il 2026-11-09 sono nel [registro per caso](BROWSER-RESULTS-2026-11-09.md#registro-per-caso): 49 PASS, 0 FAIL, 18 BLOCCATO, 5 NON ESEGUITO. `CONCLUSO` nei blocchi storici registra soltanto l’avanzamento comunicato dall’utente, senza attribuire PASS ai singoli casi. La conferma successiva dell’utente chiude A–K; L va valutato sulla nuova UI, senza convertire gli esiti storici per caso.

Puoi mandarmi anche solo i casi che non sono `PASS`.

Per ogni caso appena eseguito copia questo modello e compila una riga. L'ora serve per collegare il risultato ai check e alle email; non includere URL firmati, token o password.

| Caso | Esito | Account/ruolo e team | URL senza segreti | Ora e cosa hai osservato | Prossimo passo/ripristino |
|---|---|---|---|---|---|
| … | … | … | … | … | … |

Riepilogo dei blocchi, con risultati delegati del 2026-11-09:

| Blocco | Casi | Esito | Note |
|---|---|---|---|
| A — Accesso | A01–A10 | CONCLUSO | Conferma complessiva dell’utente il 2026-11-09; resta L |
| B — Monitor | B01–B21 | CONCLUSO | Conferma complessiva dell’utente il 2026-11-09; resta L |
| C — Heartbeat | C01–C06 | CONCLUSO | Conferma complessiva dell’utente il 2026-11-09; resta L |
| D — Notifiche | D01–D11 | CONCLUSO | Conferma complessiva dell’utente il 2026-11-09; resta L |
| E — Incidenti | E01–E17 | CONCLUSO | Conferma complessiva dell’utente il 2026-11-09; resta L |
| F — Manutenzioni e quiet hours | F01–F10 | CONCLUSO | Confermato dall'utente il 2026-11-05; correzione quiet hours documentata in QH.01 |
| G — Dashboard e report | G01–G07 | CONCLUSO | Avanzamento fino a H12 comunicato il 2026-11-06; chiarito il percorso dei grafici in G03, esiti singoli non dettagliati |
| G — Tempo reale, facoltativo | G08–G10 | DA CONFERMARE | Difetto fetch corretto in BT.03 e verificato automaticamente; nessun PASS manuale dei tre casi confermato |
| H — Status page | H01–H42 | CONCLUSO | Conferma complessiva dell’utente il 2026-11-09; precedenti blocchi inbox nel registro storico |
| H — Dominio reale, facoltativo | H43–H46 | NON ESEGUITO | Nessun dominio reale fornito |
| I — Permessi e isolamento | I01–I19 | CONCLUSO | Conferma complessiva dell’utente il 2026-11-09; nessuna nuova prova dell’agente |
| J — Limiti di piano | J01–J06 | PASS | Tutti osservati; fixture Free eliminate |
| K — Amministratore | K01–K05 | CONCLUSO | Confermato dall’utente dopo BT.14; correzioni override in BT.13/BT.14 |
| L — Accessibilità e usabilità | L01–L08 | DIFFERITO A UI.06 | Su richiesta dell’utente, dopo dashboard/navigazione fase A. Tutti i casi da verificare sulla nuova UI; PASS precedenti conservati come storici |

### Difetti trovati

| Caso | URL e account | Atteso | Ottenuto | Gravità |
|---|---|---|---|---|
| G08–G10, realtime | `/teams/2/monitors`, GiGa Team | Aggiornamento al completamento dei check senza ricarica | Banner di errore del recupero; contesto nativo di fetch corretto in BT.03, gate e prova Firefox isolata superati. Conferma manuale specifica ancora da registrare | Funzionale, correzione implementata |
| L05 / L07, offline | `/teams/3/incidents/9`, owner sintetico | Errore comprensibile nel modulo, nessuna eccezione JS non gestita | Difetto iniziale documentato nel registro; corretto in BT.08 e ripetuto con PASS in Brave/Firefox. [Correzione e verifiche](BROWSER-RESULTS-2026-11-09.md#correzione-e-ripetizione-bt08) | Funzionale, corretto e verificato |

### Note sulla grafica

Da usare come spunto per i task UI, non come difetti da correggere ora.

| Pagina | Cosa non funziona o cosa vorresti | Confronto con UptimeRobot |
|---|---|---|
| Lista/dettaglio monitor e gestione status page a 390 px | Debordamenti corretti in BT.08: documento a 390 px, navigazione su più righe e tabelle scorrevoli da tastiera. [Prima e dopo](BROWSER-RESULTS-2026-11-09.md#correzione-e-ripetizione-bt08) | Correzione funzionale verificata; restyling generale rinviato ai task UI |
