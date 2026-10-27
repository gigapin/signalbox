# Test manuali nel browser prima di M4

Checklist per verificare l’applicazione disponibile dopo M3.08, prima di implementare incidenti e manutenzioni. Integra i [test automatici](TESTING.md); non sostituisce le prove di concorrenza, isolamento dei worker e timeout già eseguite.

**Stato iniziale: test manuali non eseguiti.** Compilare il registro finale durante la sessione. La creazione di questo documento non attiva lo scheduler, non modifica configurazioni e non applica migration.

## 1. Ambiente e preparazione

| Voce | Preparazione e controllo |
| --- | --- |
| Ambiente | Sviluppo locale `APP_ENV=local`, con dati esclusivamente sintetici. Non usare produzione o il database automatico `signalbox_test` per queste prove manuali. |
| Applicazione | Stack Compose di [BOOTSTRAP.md](BOOTSTRAP.md), configurato in `compose.yaml`. URL predefinito `http://127.0.0.1:8000`; sostituirlo se `APP_PORT`/`APP_URL` sono diversi. |
| Servizi | `web`, PostgreSQL e Redis disponibili. Per lo smoke A–G basta mantenere `MONITOR_SCHEDULING_ENABLED=false`; i servizi queue/scheduler possono essere già avviati ma non devono acquisire nuovi check con il gate disabilitato. |
| Schema | Migration dello sviluppo aggiornate, comprese heartbeat `2026_10_26_000011` e risultati `2026_10_26_000012`. Verificare lo stato; applicare quelle mancanti deliberatamente prima delle prove, seguendo il bootstrap. |
| Frontend | Asset Vite compilati e pagina caricabile senza errori di manifest. Non serve il server HMR per questa checklist. |
| Browser | Un browser Chromium e, se disponibile, Firefox o Safari. Annotare browser/versione. Usare profili separati per i ruoli, così le sessioni non si sovrascrivono. |
| DevTools | Aprire Console, Network e Application/Storage. Controllare richieste e risposte; non esportare HAR o screenshot contenenti token, cookie o password. |
| Email | Account verificati. Il bootstrap locale usa `MAIL_MAILER=log`: completare l’eventuale verifica email tramite il messaggio locale, senza assumere che un account appena creato sia già verificato. |
| Piani | Annotare piano effettivo, trial e override di ogni team. Un team nominalmente Free può avere il trial Pro: i limiti vanno confrontati con l’entitlement effettivo. |
| Stato iniziale | Registrare numero di monitor, ID dei team e ID dei monitor di prova. Non affidarsi a ID fissi e non eliminare dati preesistenti. |

Comandi di sola verifica, da eseguire dalla radice del progetto se utili:

```sh
docker compose ps
docker compose exec web php artisan migrate:status
```

Questi comandi non preparano automaticamente gli account o i dati. Eventuali seed, migration, assegnazioni di ruolo/piano e avvii dei servizi sono operazioni di preparazione deliberate. Non utilizzare `migrate:fresh` o reset del database per eseguire questa checklist.

### Account e ruoli

| Identità | Accesso richiesto | Cosa verificare |
| --- | --- | --- |
| Owner A | Owner del team Alpha | Tutte le operazioni sui monitor e sulle credenziali heartbeat. |
| Admin A | Admin di Alpha | Stesse operazioni sui monitor dell’owner; il ruolo admin di team non concede accesso alla piattaforma. |
| Member A | Member di Alpha | Può creare, modificare, eliminare, sospendere/riprendere monitor e gestire heartbeat. |
| Viewer A | Viewer di Alpha | Lettura di lista/dettaglio; creazione, modifica e credenziali non disponibili. |
| Estraneo | Utente verificato appartenente solo a un team Gamma | Nessun accesso alle risorse di Alpha/Beta. |
| Operatore, opzionale | Utente con flag piattaforma già assegnato deliberatamente | Diagnostica Horizon e gestione di eventuali override; non necessario per lo smoke ordinario. |

Se è già stato eseguito `IdentitySeeder`, esistono `owner@signalbox.test`, `admin@signalbox.test`, `member@signalbox.test`, `viewer@signalbox.test` e i team `synthetic-alpha`/`synthetic-beta`. La password iniziale è `password` **solo alla prima creazione**: il seeder preserva credenziali e dati esistenti. Verificare lo stato email.

**Tutti e quattro gli account del seeder appartengono a entrambi i team.** Per provare una membership assente serve l’account Estraneo; per provare un monitor sotto il team errato basta combinare il team Alpha con l’ID di un monitor Beta.

### Dati di prova

Creare in Alpha i seguenti monitor, entro la quota effettiva. I nomi rendono riconoscibili i dati da pulire a fine sessione.

| Nome | Tipo | Configurazione per lo smoke con motore disabilitato |
| --- | --- | --- |
| `BROWSER-HTTP-A` | HTTP | `https://example.invalid/health?api_key=browser-smoke-secret`, GET, status atteso 200; header `Authorization: Bearer browser-smoke-secret`. |
| `BROWSER-KEYWORD-A` | Keyword | `https://example.invalid/keyword`, parola `healthy`, modalità presenza. |
| `BROWSER-TLS-A` | TLS | Host `example.invalid`, porta 443, soglia avviso 30 giorni. |
| `BROWSER-DNS-A` | DNS | Nome `example.invalid`, tipo A, valore atteso `192.0.2.10`, resolver vuoto/default. |
| `BROWSER-HEARTBEAT-A` | Heartbeat | Intervallo 300 secondi, tolleranza 7 secondi. |

Usare intervallo 300 secondi, timeout 5000 ms e regione EU dove applicabili. In Beta creare un monitor con lo stesso nome di uno di Alpha, per verificare ricerca e isolamento.

Gli host `.invalid` e gli indirizzi di documentazione servono a configurare dati sintetici: **non sono target da cui aspettarsi un check riuscito**. La sezione H richiede destinazioni controllate realmente raggiungibili. Non inviare le credenziali sintetiche a servizi pubblici di terzi.

## 2. Smoke con motore disabilitato

Eseguire A–G nell’ordine indicato. Durata orientativa: 30–45 minuti, oltre alla preparazione degli account. I controlli di quota e paginazione richiedono dati aggiuntivi e sono indicati come opzionali.

### A. Accesso e navigazione

| ID | Ruolo | Passaggi | Risultato atteso |
| --- | --- | --- | --- |
| A01 | Non autenticato | Aprire `/teams/{ALPHA_ID}/monitors` in una finestra privata. | Richiesta di login; nessun dato del team mostrato. |
| A02 | Owner A | Accedere, selezionare Alpha e aprire lista → creazione → dettaglio → modifica → lista. | Pagine utilizzabili, URL coerenti, nessun errore JavaScript o risposta 500. |
| A03 | Account non verificato, se disponibile | Aprire la lista monitor. | Richiesta di verifica email; nessuna risorsa protetta accessibile. |
| A04 | Owner A | Ripetere la navigazione con avanti/indietro e refresh del browser. | Nessuna pagina bloccata, azione duplicata o monitor duplicato. Le risposte private devono avere `Cache-Control: no-store, private`. |

Sostituire `{ALPHA_ID}` e gli altri segnaposto con gli ID annotati. Le pagine monitor sono `/teams/{team}/monitors`, `/create`, `/{monitor}` e `/{monitor}/edit`.

### B. Creazione, modifica e validazione

| ID | Ruolo | Passaggi | Risultato atteso |
| --- | --- | --- | --- |
| B01 | Owner A | Creare i cinque monitor della tabella dati. | Compaiono solo i campi del tipo selezionato; salvataggio seguito dal dettaglio del nuovo monitor e messaggio di successo. Stato osservato iniziale `unknown`. |
| B02 | Admin A, poi Member A | Rinominare un monitor e modificarne una configurazione non segreta; ripristinare poi il valore iniziale. | Operazione consentita per entrambi i ruoli. Il member non è limitato alla sola lettura. |
| B03 | Owner A | Inviare nome vuoto, timeout/intervallo fuori limite, TLS senza host o DNS senza valori attesi. | Nessuna creazione/modifica riuscita; errori comprensibili presso i campi e focus sul primo errore o sull’alert. Nessun errore 500. |
| B04 | Owner A | Provare URL `http://127.0.0.1/`, URL con credenziali incorporate e resolver DNS locale/privato. | Configurazioni rifiutate con errore sicuro. Ripristinare i dati sintetici validi. |
| B05 | Owner A | Cambiare il tipo di un monitor di prova senza compilare la nuova configurazione, quindi completarla. | Prima il salvataggio è rifiutato; dopo la configurazione completa è accettato. Nessun campo del vecchio tipo inviato come configurazione attiva. |
| B06 | Owner A | Aprire la modifica HTTP e salvare solo un nuovo nome, senza selezionare la sostituzione dei segreti. | URL/header/body esistenti preservati; nessun segreto precompilato in chiaro. Il salvataggio non deve cancellare la configurazione omessa. |
| B07 | Owner A | Usare i controlli espliciti di sostituzione URL/header/body, poi ricaricare la pagina. | Valori sostituiti secondo la scelta; la pagina mostra nuovamente la proiezione sicura. Verificare rimozione del body con l’opzione dedicata. |

### C. Lista, ricerca e stato operativo

| ID | Ruolo | Passaggi | Risultato atteso |
| --- | --- | --- | --- |
| C01 | Owner A | Cercare `BROWSER-`, combinare tipo e stato operativo, poi azzerare i filtri. | Risultati coerenti, conteggio aggiornato dopo la navigazione, nessuna riga di Beta. La ricerca parte con l’invio del form. |
| C02 | Owner A | Cercare una stringa inesistente. | Stato “nessun risultato” con possibilità di rimuovere i filtri; distinto dal team senza monitor. |
| C03 | Owner A | Cercare per nome HTTP/keyword e per host TLS/nome DNS. | Ricerca funzionante sui campi previsti. Non aspettarsi ricerca nell’URL cifrato di HTTP/keyword. |
| C04 | Owner A | Aggiungere alla lista un filtro non valido, ad esempio `?type=nonexistent`. | Avviso sul filtro rifiutato e pagina comunque utilizzabile; nessun ciclo di redirect. |
| C05 | Owner A | Pausare un monitor dal dettaglio, aggiornare la lista, poi riprenderlo. | Stato operativo `paused` e poi `active`; lo stato osservato è distinto dallo stato operativo. Con motore spento non si generano nuovi risultati. |
| C06 | Owner A | Avviare un’azione con rete rallentata da DevTools e provare un secondo clic. | Indicazione di caricamento e controlli dell’azione disabilitati; nessuna duplicazione dell’operazione. Riportare la rete alla modalità normale. |
| C07, opzionale | Owner di team Pro/Business con oltre 20 monitor sintetici | Passare alla seconda pagina con filtri attivi e tornare indietro. | Paginazione conserva i filtri; ordine stabile per nome/ID, senza righe duplicate tra pagine. Non assegnare un override solo per questo caso se non si vuole preparare quel contesto. |

### D. Permessi e isolamento tenant

| ID | Ruolo | Passaggi | Risultato atteso |
| --- | --- | --- | --- |
| D01 | Viewer A | Aprire lista e dettaglio; cercare pulsanti di creazione/modifica/eliminazione/pausa e credenziali. | Lettura consentita, nessun controllo di gestione disponibile. |
| D02 | Viewer A | Aprire direttamente `/teams/{ALPHA_ID}/monitors/create` e `/{MONITOR_ALPHA_ID}/edit`. | Accesso rifiutato con 403; nessuna modifica o configurazione privata esposta. |
| D03 | Owner A | Sotto il percorso di Alpha sostituire l’ID del monitor con quello di Beta, anche nella pagina edit. | 404, anche se l’account è membro di entrambi i team. Nessun dato del monitor Beta sotto Alpha. |
| D04 | Estraneo | Aprire direttamente lista e dettaglio di Alpha. | 404; non basta conoscere gli ID. |
| D05 | Owner membro di Alpha e Beta | Cercare il nome comune in Alpha, cambiare team e cercarlo in Beta. | Ogni lista mostra solo i monitor del proprio team. Il percorso esplicito identifica il team della risorsa, indipendentemente dall’ultima preferenza salvata. |
| D06 | Owner/Admin/Member senza ruolo piattaforma | Aprire `/admin` e `/horizon`. | Accesso negato/404; un ruolo di team non conferisce privilegi di operatore. |

La negazione delle mutation HTTP dirette è già coperta dalla matrice automatica. Se si riproduce una richiesta da DevTools come viewer, usare solo un monitor sacrificabile: attendersi 403 e nessuna modifica, mantenendo validi i normali controlli CSRF.

### E. Heartbeat e credenziale una sola volta

| ID | Ruolo | Passaggi | Risultato atteso |
| --- | --- | --- | --- |
| E01 | Owner A | Aprire il dettaglio heartbeat e generare una credenziale. | Token/comando visualizzati solo nella risposta di emissione e nello stato corrente del pannello; risposta privata `no-store`. |
| E02 | Owner A | Copiare il comando, nasconderlo, ricaricare, cambiare pagina e tornare indietro. | La credenziale non viene recuperata né mostrata nuovamente. La copia avviene solo su azione esplicita; un permesso clipboard negato produce un errore gestibile. |
| E03 | Owner A o chiamante senza login | Eseguire il comando `curl` appena generato, oppure una POST equivalente con header Bearer. Ricaricare il dettaglio. | `POST /heartbeats` restituisce **204** senza corpo; si aggiorna l’ultima ricezione e la scadenza calcolata. Con motore spento questo non genera un CheckResult e non forza lo stato osservato a `up`. |
| E04 | Owner A | Prima di nascondere il comando salvare temporaneamente il token sintetico per la prova; ruotare la credenziale e confrontare vecchio/nuovo token. | Conferma richiesta; il vecchio token restituisce **404**, il nuovo **204**. Rotazione senza reset della scadenza. |
| E05 | Owner A | Revocare la credenziale e riprovare il token appena revocato. | Conferma richiesta, poi **404**; nessuna nuova ricezione registrata. Per proseguire serve una nuova credenziale. |
| E06 | Chiamante senza login | Inviare una POST senza Bearer e una con token inventato. | Entrambe **404** generiche, senza ID o dettagli del monitor. Il login browser non sostituisce il Bearer. |
| E07 | Owner A | Con token valido, pausare il monitor; inviare un ping e aggiornare il dettaglio. | Risposta **204** ma ultima ricezione invariata mentre il monitor è inattivo. Ripresa effettiva riarma la finestra; registrare un nuovo ping dopo la ripresa. |
| E08 | Viewer A | Aprire il dettaglio heartbeat. | Informazioni non segrete leggibili; nessun pulsante per generare/ruotare/revocare e nessun token. |
| E09 | Owner A | In DevTools impostare Offline prima di un’operazione credenziale, poi ripristinare Online e riprovare. | Errore comprensibile e pulsanti nuovamente utilizzabili; nessuno spinner permanente o errore grezzo del server. |

Per E03–E07 usare il comando generato dall’interfaccia; non incollare token nel documento o negli screenshot. Aprire `/heartbeats` nella barra degli indirizzi invia una GET e non costituisce un ping: il metodo richiesto è POST; una GET riceve 405.

### F. Segreti e accessibilità

| ID | Ruolo | Passaggi | Risultato atteso |
| --- | --- | --- | --- |
| F01 | Owner A | Ispezionare le risposte Inertia di lista/dettaglio/modifica del monitor HTTP. | URL redatto nei valori query; header mostrati come nomi, senza valori; body rappresentato con presenza/dimensione. Nessuna credenziale o ciphertext nelle props. |
| F02 | Owner A | Dopo emissione heartbeat controllare props Inertia, localStorage e sessionStorage. | Token non salvato in queste destinazioni, nella URL o nei messaggi flash. La risposta JSON di emissione contiene intenzionalmente il token una sola volta. |
| F03 | Tutti i ruoli | Usare Tab/Shift+Tab/Invio per navigazione, filtri, form, conferme e paginazione disponibile. | Focus visibile, controlli raggiungibili, etichette comprensibili; badge con testo oltre al colore. |
| F04 | Owner A | Ripetere lista/dettaglio/form con viewport mobile e zoom al 200%. | Contenuti e azioni raggiungibili, senza campi o pulsanti inutilizzabili. Annotare eventuali problemi di tabella o overflow. |
| F05 | Tutti i ruoli | Controllare Console e Network durante i casi precedenti. | Nessun errore JavaScript inatteso o risposta 500; i 403/404 e gli errori di rete provocati deliberatamente sono evidenza dei relativi casi, non fallimenti generici. |

Le richieste di creazione/sostituzione devono trasportare i valori inseriti dall’utente e il ping deve trasportare il Bearer: non considerarli una fuga di dati prevista nelle risposte. Dopo le prove eliminare le copie temporanee dei token e svuotare la clipboard usata per il comando.

### G. Eliminazione e limiti

| ID | Ruolo | Passaggi | Risultato atteso |
| --- | --- | --- | --- |
| G01 | Owner A | Eliminare un monitor sacrificabile: prima annullare la conferma, poi confermare. | Annullamento senza effetti; conferma con ritorno alla lista. Il vecchio dettaglio restituisce 404. |
| G02, opzionale | Owner di un team realmente Free, fuori trial e senza override | Raggiungere cinque monitor, anche pausandone uno, poi tentare il sesto. | Limite indicato nella UI e creazione rifiutata dal backend; pausare non libera quota. Eliminare un monitor libera invece un posto. |
| G03 | Owner A | Tentare un intervallo sotto il minimo del piano effettivo. | Messaggio di validazione/limite coerente con Free 300 s, Pro 60 s, Business 30 s. Riferimento: `config/plans.php`. |

Non aspettarsi il limite Free di cinque su un team in trial Pro o con override interno. Non modificare direttamente i dati di abbonamento per far “passare” una prova: predisporre deliberatamente il team necessario e annotarlo.

## 3. H — Prove opzionali con motore attivo

Eseguire questa sezione solo scegliendo esplicitamente un ambiente di prova con dati sintetici e destinazioni controllate. Lo smoke A–G può essere concluso senza attivarla. Un caso H non eseguito va segnato `NON ESEGUITO`, senza trasformarlo in un errore di interfaccia.

### Preparazione aggiuntiva

1. Sostituire i target `.invalid` con endpoint/host/record sotto il proprio controllo e raggiungibili dal container PHP. Predisporre risposta 200, risposta 503, contenuto keyword e record DNS atteso. Le protezioni SSRF restano attive; non allentare globalmente i CIDR per raggiungere localhost o servizi interni.
2. Usare un team con intervallo adatto al piano. Un piano Business consente 30 secondi; Free richiede almeno 300 secondi. Annotare intervallo e regioni.
3. Attivare deliberatamente `MONITOR_SCHEDULING_ENABLED=true` nella sola configurazione dell’ambiente scelto e assicurarsi che web, scheduler e Horizon abbiano ricaricato la configurazione. Verificare servizi `scheduler` e `queue` e worker `checks:eu`/`checks:us`.
4. Se si vuole usare Horizon, predisporre un operatore già autorizzato. Non promuovere un owner a operatore come parte implicita del test.
5. Attendere l’inizializzazione di `next_check_at` e lo slot successivo. Non esiste un pulsante “Run now” da usare nella UI corrente. Aggiornare manualmente la pagina per osservare i riepiloghi: il realtime appartiene a M6.

| ID | Ruolo | Passaggi | Risultato atteso |
| --- | --- | --- | --- |
| H01 | Owner A | Lasciare HTTP sano per uno slot; cambiare il target controllato in 503 per uno slot; ripristinare 200. | Dopo i rispettivi slot e refresh: `up` → `down` → `up`, con ultimo controllo aggiornato. La mancata puntualità al secondo esatto non è da sola un difetto. |
| H02 | Owner A | Cambiare il contenuto del target keyword per violare l’aspettativa, poi ripristinarlo. | Stato `down` e poi `up` dopo le osservazioni corrispondenti. |
| H03 | Owner A | Usare host TLS verificabile e record DNS controllato; introdurre un errore e poi ripristinare. | Errori registrati come esiti dei check e successivo recupero. Il riepilogo TLS usa una scadenza verificata. Evitare modifiche a record o certificati usati da altri servizi. |
| H04 | Owner A | Su heartbeat attivo inviare un ping; interromperli oltre intervallo+tolleranza; riprendere i ping. | Dopo gli slot utili: `up` → `down` → `up`. Il rilevamento avviene agli slot programmati, non tramite timer istantaneo alla scadenza. Un ping tardivo non riscrive il risultato precedente. |
| H05 | Owner A | Pausare un monitor attivo e poi riprenderlo. | Nessun nuovo check acquisito durante la pausa; un check già acquisito può terminare come storico. Ripresa con nuovo slot futuro, senza raffica di recupero. |
| H06, opzionale | Owner A | Configurare EU e US per un monitor e osservare il riepilogo. | `up`/`down` concorde solo dopo entrambe le osservazioni dello slot; dati parziali o discordanti danno `unknown`. Non dedurre la contesa dei worker dalla sola UI: è già verificata dai test automatici. |

La UI corrente espone riepiloghi, non un elenco completo dei CheckResult o delle relative diagnostiche. Per contare risultati, distinguere guasto target da infrastruttura o provare timeout/kill/deduplica usare la [matrice automatica M3.08](TESTING.md#integrated-engine-acceptance-m308) ed eventuale diagnostica autorizzata. Nessun incidente o invio di notifica è atteso in questa fase: appartengono a M4/M5.

### Chiusura delle prove H

Ripristinare il valore iniziale del gate e farlo ricaricare ai processi coinvolti. Il gate spento impedisce nuove acquisizioni; eventuale lavoro già acquisito può ancora completarsi. Rimuovere solo le modifiche e i target creati per la sessione, senza cancellare risultati o dati preesistenti per ottenere un esito migliore.

## 4. Registro degli esiti e pulizia

Compilare una riga per **ogni ID** eseguito, ripetendola per ruolo/browser quando necessario. Esiti ammessi: `PASS`, `FAIL`, `BLOCCATO`, `NON ESEGUITO`; specificare il motivo per gli ultimi due. Se un caso fallisce, registrare prima l’evidenza, poi ripetere dopo la correzione conservando entrambi gli esiti.

Dati della sessione:

| Campo | Valore da compilare |
| --- | --- |
| Data, operatore | |
| Browser/versione, sistema, viewport | |
| URL e versione applicativa/branch riferiti dall’operatore | |
| Team/ID, ruolo e piano effettivo | |
| Gate iniziale/finale e servizi utilizzati | |
| Migration mancanti o altri impedimenti | |

Registro:

| Caso | Ruolo / team | Browser | Esito | Risultato osservato, evidenza sanificata e difetto collegato |
| --- | --- | --- | --- | --- |
| A01 | | | NON ESEGUITO | |

Checklist finale:

- Rimuovere soltanto monitor/account creati per la prova e di cui non si vuole conservare lo storico; rispettare le conferme di eliminazione.
- Revocare le credenziali heartbeat temporanee e rimuovere token dagli appunti e dalla clipboard.
- Ripristinare rete Online, throttling, viewport, eventuali override e configurazioni modificati deliberatamente.
- Verificare che il gate e i processi siano tornati allo stato iniziale concordato.
- Registrare i casi non eseguiti, senza dichiarare una sessione browser completa per deduzione dai test automatici.

Considerare concluso lo smoke essenziale quando i casi A–G non opzionali risultano verificati, con esito positivo o difetto registrato. Chiudere i difetti su accessi tra tenant, permessi, esposizione dei segreti, perdita di configurazioni o errori 500 prima di ampliare le funzionalità. Eventuali problemi visivi minori possono essere tracciati separatamente con una priorità esplicita. Le prove H costituiscono un collaudo integrato aggiuntivo e non sono un prerequisito per documentare lo smoke con motore spento.
