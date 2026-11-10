# UI guidelines — Signalbox

UI.01.3 introduce token e primitive condivise, basate sui [percorsi UI.01.2](USER-DASHBOARD.md) e sull’inventario UI.01.1. Implementazione in [app.css](../resources/css/app.css) e [components/ui](../resources/js/components/ui). Non introduce AppLayout, nuove rotte, librerie o metriche. Le pagine saranno migrate in UI.02–UI.04; badge monitor/incidenti e viewport tabellare adottano già la base condivisa.

## Token e composizione

Tema scuro iniziale: canvas blu scuro, superfici rialzate, azione primaria lilla, stati semantici verde/ambra/rosa/azzurro. Nome testuale da configurazione e identità definitiva restano nel proprio task; non introdurre loghi. I token `--color-ui-*`, `--spacing-ui-*`, `--text-ui-*`, `--font-ui` e `--radius-ui` sono dichiarati con Tailwind `@theme`, senza cambiare i token esistenti. Le regole sono circoscritte a classi `ui-*`: nessuno stile globale di input, link o body altera i temi delle status page pubbliche.

| Token/gruppo | Valori e uso |
|---|---|
| canvas / surface / raised | `#0b1220` / `#152033` / `#202e44`; sfondo shell, pannelli, stato neutro |
| text / muted | `#f1f5fb` / `#b5c3d8`; testo primario e istruzioni, mai opacity per rendere secondario un dato importante |
| border / focus | `#72849e` / `#b9a4ff`; confini dei controlli, ring di 2 px con offset 3 px |
| accent / accent-hover / on-accent | `#b9a4ff` / `#cbbcff` / `#15102a`; azioni primarie, testo scuro sul pulsante |
| success / success-bg | `#9be2ba` / `#12352b` |
| warning / warning-bg | `#f9d48a` / `#3d2c17` |
| danger / danger-bg | `#ffb4bb` / `#44202c` |
| info / info-bg | `#a8d8ff` / `#172f49` |
| Tipografia | Font di sistema senza download; body 1 rem, testo secondario/badge 0.875 rem, h2 1.25 rem, h1 1.875 rem; line-height corpo 1.5 |
| Spaziatura | Scala 0.25 / 0.5 / 0.75 / 1 / 1.5 / 2 rem, padding pannelli 1.5 rem; controlli almeno 2.75 rem (44 px con root standard) |
| Angoli | Raggio 0.625 rem per controlli, badge e superfici |

`ui-theme` è la base opt-in per il futuro layout: applica canvas, testo e font; non crea sidebar o navigazione. Le utility Tailwind semantiche come `bg-ui-surface` e `text-ui-muted` sono disponibili attraverso i token. Le primitive possiedono già i propri colori di superficie. Nuovi elementi non devono introdurre colori arbitrari per gli stessi significati.

Contrasto: testo normale almeno **4.5:1**, confini dei controlli e indicatori focus almeno **3:1** rispetto alle superfici adiacenti previste. [UiContrast.test.ts](../tests/frontend/UiContrast.test.ts) legge i valori CSS effettivi e verifica luminanza sRGB, testo primario/secondario/link/errori sui tre sfondi, tutti gli stati e hover primario, bordi e focus. Queste verifiche numeriche non certificano una pagina futura o sostituiscono VoiceOver/zoom/browser. Nuove combinazioni di sfondo/foreground richiedono una verifica della coppia; non usare overlay/opacità che cambino il colore effettivo senza misurarlo.

## Contratti delle primitive

Le primitive presentano contenuti forniti dal chiamante: non caricano dati, non possiedono policy, non ritentano richieste e non calcolano SLA. Importare i componenti dai rispettivi file senza una nuova dipendenza UI.

| Componente | Interfaccia e comportamento | Responsabilità del chiamante |
|---|---|---|
| [Button](../resources/js/components/ui/Button.tsx) | Attributi/ref nativi; `variant` primary/secondary/danger, `busy`, `busyLabel`. Default `type="button"`; busy imposta disabled e aria-busy, sostituendo il testo con Working… o label fornita | Submit esplicito con `type="submit"`; pending dalla richiesta; etichetta concreta (“Saving monitor…”), motivo di blocco leggibile vicino al comando; conferma distruttiva e autorizzazione esterne |
| [InputField](../resources/js/components/ui/InputField.tsx) | Props/ref nativi input con `label` obbligatoria, `hint`, `error`; ID automatico o esplicito. Label required testuale, aiuti/errori uniti ad aria-describedby preesistente; errore imposta aria-invalid e regione polite stabile | Usare per input testuali/numerici/data/password. Checkbox/radio, hidden e upload richiedono composizione nativa appropriata, non questo campo. Nome/value/onChange/limiti e validazione appartengono al form; nessuna persistenza o sanificazione silenziosa |
| [Panel](../resources/js/components/ui/Panel.tsx) | Section nominata dal titolo con ID univoco, headingLevel 2 predefinito o 3 se annidato; description facoltativa | Scegliere gerarchia heading corretta; fornire caricamento, vuoto, errore o contenuto; nessun finto dato per riempire il pannello |
| PageHeading, nello stesso file | Un h1, description e actions che vanno a capo | Un solo PageHeading per pagina; il layout imposta separatamente document title; azioni già filtrate dai permessi |
| [Badge](../resources/js/components/ui/Badge.tsx) | Testo con tone neutral/success/warning/danger/info; nessun live region | Etichetta esplicita e significato di dominio. Stato osservato e operativo restano distinti; colore non sufficiente |
| [Alert](../resources/js/components/ui/Alert.tsx) | title/children, tone, `announce` off/polite/assertive; statico per default, role status/alert solo su richiesta | Polite per conferme nuove, assertive per errori bloccanti nuovi; non annunciare ogni badge o intera pagina ad ogni evento. Nessuna eccezione grezza/credenziale nei children; eventuali retry/dismiss sono comandi espliciti del chiamante |
| [DataTable](../resources/js/components/ui/DataTable.tsx) | caption visibile, indicazione di scorrimento, TableScrollArea focusabile/nominata, tabella nativa con children thead/tbody/tfoot | th con scope col/row, link nominativi, nessuna colonna indispensabile nascosta; filtri/paginazione e vuoto fuori dalla tabella. L’overflow resta locale |
| [TableScrollArea](../resources/js/components/TableScrollArea.tsx) | Conserva `label`/`children`; aggiunge `describedBy` opzionale per istruzioni esterne, focus semantico condiviso | Riutilizzabile dalle tabelle legacy senza ricostruire markup o nomi accessibili |

Le classi `ui-input` e `ui-label` possono accompagnare select/textarea nativi con label, aiuti ed errori correttamente associati; non esiste un wrapper che inventi opzioni o risposte server. `ui-link` mantiene underline e focus per link nativi o Inertia: la navigazione resta un link, l’azione resta un button. Non aggiungere un finto link disabilitato per una rotta assente. Comporre una famiglia nuova soltanto quando un flusso reale ne richiede il comportamento.

Adozione iniziale: [MonitorBadges](../resources/js/components/monitors/MonitorBadges.tsx) e [IncidentBadges](../resources/js/components/incidents/IncidentBadges.tsx) mappano stati noti ai toni condivisi conservando i label helper; valori sconosciuti restano testo con tono neutral. Up/Down/Unknown non sono sinonimi di active/paused/suspended, e un incidente resolved non indica uptime 100%. Non vengono cambiate policy o proiezioni.

## Contenuti, accessibilità e stati

- Etichette in inglese con verbi specifici (“Create monitor”, “Pause monitor”), nome risorsa accanto alle azioni distruttive e distinzione “Public”/“Private” per gli aggiornamenti. Niente abbreviazioni senza contesto o solo icone per i comandi essenziali.
- Esplicitare unità e finestra: ms per latenza, s o testo esteso per intervalli/durate, % per percentuali e relativa copertura. Zero è una misura; null si presenta come assenza/indisponibilità, mai come 0 o 100. La primitive non converte né aggrega metriche.
- Date con `time dateTime` ISO e fuso visibile. Grafici attuali in UTC; report mensili nel fuso del team. Se si usa tempo relativo, affiancare un istante assoluto accessibile; nessuna data locale senza indicazione del fuso. La politica delle finestre/retention appartiene ai servizi metrici.
- Ordine heading e DOM seguono UI.01.2. Non eliminare outline; focus-visible con ring offset, gestione OS dei colori forzati conservata. Le primitive non hanno animazioni o timer decorativi. Errori inline polite e label persistente: il placeholder non è il nome del campo.
- I controlli disabled/busy conservano contrasto leggibile e bordo tratteggiato; il contesto deve spiegare perché non si può agire. Un pulsante occupato blocca l’attivazione, ma non dimostra idempotenza server: la richiesta e la sua gestione restano del chiamante.
- PageHeading e azioni vanno a capo; nomi e testi lunghi possono spezzarsi. Schede senza altezza fissa e tabelle con overflow locale. Sidebar da 1024 px/menu mobile e colonna contestuale da 1280 px saranno implementati in UI.02/UI.03, con prova a 390 px e zoom 200%.
- Stato vuoto come testo e azione consentita dentro Panel; caricamento con aria-busy sulla regione interessata e testo accessibile; errore con Alert e comando esplicito di recupero. Nessun auto-focus o annuncio totale ad ogni refresh realtime; il focus dopo navigazione/applicazione filtri spetta al layout/pagina.
- RequestFailureNotice resta montato una sola volta dall’app. Alert non lo sostituisce né aggiunge retry, recupero automatico, dismiss o reset dei draft. Revoca e cambio team non possono lasciare dati privati visibili: coordinamento e conferme d’abbandono restano ai task layout/flussi.

## Verifica e adozione successiva

[UiPrimitives.test.tsx](../tests/frontend/UiPrimitives.test.tsx) copre tastiera, submit esplicito e blocco busy, associazioni label/help/error, ref, conservazione valore in correzione, ID indipendenti, gerarchia heading, annunci espliciti, etichette stato/fallback e tabella navigabile. UiContrast verifica le coppie cromatiche con le soglie sopra. `npm run verify` esegue lint, Prettier, TypeScript, test qualità, suite DOM e build.

UI.01.3 completa le primitive, non l’accettazione delle pagine: implementare AppLayout a partire da UI.02.1, poi selettore/mobile/migrazione e UI.03/UI.04. Gli scenari di UI.01.2 restano criteri per quelle porzioni. L01–L08, VoiceOver effettivo e confronto Brave/Firefox saranno eseguiti in UI.06; non attribuire PASS browser ai test jsdom. Per risultati realmente eseguiti e limiti leggere [TASKS.md](TASKS.md).

## AppLayout — UI.02.1

[AppLayout](../resources/js/components/app/AppLayout.tsx) è la shell privata per pagine di un team già autorizzato dal server. La prima adozione è [Dashboard/Show](../resources/js/pages/Dashboard/Show.tsx): il menu locale è stato rimosso e report, selezione grafici, metriche e hook realtime restano della pagina. Le altre viste mantengono temporaneamente AuthLayout fino alla migrazione UI.02.4; le pagine pubbliche e di autenticazione non vengono avvolte automaticamente dalla shell.

| Input | Contratto |
|---|---|
| `team` | `{ id, name }` del team della rotta, obbligatorio; nessuna inferenza da `currentTeamId` o dal team preferito |
| `section` | dashboard/monitors/incidents/notifications/status-pages/team; distingue la famiglia corrente anche per dettaglio e modifica |
| `title`, `description`, `actions`, `children` | Unico h1/document title, contesto facoltativo, comandi già autorizzati e contenuto della pagina |
| `ancestors` | Array facoltativo di `{ label, href }` costruito dall’applicazione; la shell antepone il team e aggiunge il titolo corrente non cliccabile. Non passare input `returnTo` grezzo |
| Props condivise Inertia | `appName`, `auth.user`, `status` già esistenti. Il menu account si mostra quando l’identità è presente; Admin richiede `isPlatformAdmin === true`. Nessun nuovo campo backend |

[navigation.ts](../resources/js/components/app/navigation.ts) costruisce sei destinazioni reali del team: Dashboard, Monitors, Incidents, Notifications, Status pages e Team. Una sola voce ha `aria-current="page"`; il breadcrumb ha separatamente il proprio elemento corrente. Non mostrare Maintenance, Billing o API prima delle rispettive pagine. Le voci sono collegamenti di lettura consentiti ai viewer: non implicano permessi di mutazione.

La shell riusa `ui-theme`, PageHeading e Alert per lo status condiviso. Il nome prodotto è testuale da `appName`, con link alla dashboard del team corrente. Skip link verso il main focusabile, landmark e heading sono presenti. La sidebar da 1024 CSS px occupa una colonna di 15 rem, rimane nel flusso della griglia ed è scorrevole su schermi bassi. Sotto soglia, la stessa navigazione è per ora visibile sopra il contenuto e va a capo: è una fase intermedia funzionante, il dialogo mobile e la sua gestione focus appartengono a UI.02.3. Selettore, ruolo corrente e cambio team sono UI.02.2; la shell non li simula.

[AccountMenu](../resources/js/components/app/AccountMenu.tsx) è una disclosure con button/aria-expanded/aria-controls, link nativi Inertia e normali Tab: non usa role menu. Contiene Your account, Your teams, Account security, Admin per operatori e un form POST Sign out. Escape chiude e ripristina il focus al trigger; click esterno chiude senza spostare il focus; navigazione chiude la disclosure. I listener sono rimossi alla chiusura/unmount. Logout usa `useForm`, payload vuoto e stato busy; nessuna GET di logout o replay automatico. La visibilità Admin è soltanto una proiezione: middleware/controlli server esistenti rimangono autorevoli, indipendentemente dal ruolo tenant.

[AppLayout.test.tsx](../tests/frontend/AppLayout.test.tsx) copre team esplicito contro preferenza differente, tutte le sezioni correnti, breadcrumb, branding configurabile, skip link, conservazione bozza, tastiera/Escape/click esterno, diniego del link Admin per i quattro ruoli tenant, presenza per flag operatore, navigazione e logout POST singolo durante pending. Suite Dashboard e RealtimePages verificano anche il primo utilizzo reale della shell. Test DOM e CSS non attestano un collaudo browser o la navigazione uniforme sulle viste ancora da migrare.
