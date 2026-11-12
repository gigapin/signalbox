# Prove native ancora da osservare — UI.06.3

**Aggiornamento 2026-11-12:** L03 completato tramite zoom nativo browser; [metodo ed evidenza](BROWSER-ACCESSIBILITY-2026-11-12.md).
**L02 completato:** l’utente conferma entrambi i controlli Name vuoto (monitor e canale) con VoiceOver in Brave e Firefox. Le istruzioni sotto restano riproducibili; nessuna nuova esecuzione richiesta per chiudere UI.06.3. UI.06.4 ha poi verificato VoiceOver non in esecuzione e i ripristini finali; [fase A accettata](PHASE-A-ACCEPTANCE.md). I passi L03 sotto restano una procedura
di riproduzione facoltativa, senza richiedere una nuova prova per chiudere UI.06.3.

Questi passi descrivono L02 e la riproduzione di L03 della [guida browser](BROWSER-TESTS.md).
Servono osservazioni sul desktop: controlli ARIA, screenshot a 390 px o zoom CSS non attestano
annunci VoiceOver e zoom nativo. Non comunicare password, token, codici o cookie.

## Ambiente

Apri `http://localhost:8000/login` in Brave e poi in Firefox desktop, annotando le due versioni.
Per le sole prove UI bastano web/PostgreSQL/Redis, con asset compilati e migration già applicate;
scheduler, worker e Reverb possono restare fermi. Se l’app è arrestata:

```sh
docker compose up -d web
```

Usa il tuo account autorizzato su un team sintetico oppure le fixture documentate in
[MANUAL-BROWSER-TESTS.md](MANUAL-BROWSER-TESTS.md#account-e-ruoli). Non modificare account personali,
piani o membership. Per questa installazione Alpha è il team 3, il monitor sintetico 7 e l’incidente
risolto 9; verifica i nomi prima di usare gli indirizzi. Non inviare nuove note durante queste prove.

## L02 — Annunci reali di errore

Attiva VoiceOver con ⌘F5 (eventualmente Fn+⌘F5) oppure dalle impostazioni Accessibilità di macOS;
istruzioni ufficiali [Apple](https://support.apple.com/en-gb/guide/voiceover/vo2682/10/mac/27).
Annota se era già attivo, così alla fine puoi ripristinare lo stato precedente.

1. Apri `/teams/3/monitors/create`. Lascia **Name** vuoto, **Type = HTTP**, **Interval (seconds) = 300**,
   **Timeout (milliseconds) = 5000**, **Failures before alerting = 2**, sola **Europe (simulated)**,
   **URL = https://example.com**, **Expected status = 200**. Attiva **Create monitor**.
   Deve comparire **The name field is required.** vicino a Name; il focus deve raggiungere quel campo.
   Registra ciò che VoiceOver annuncia spontaneamente, senza leggere manualmente l’errore prima
   di valutare l’annuncio. Nessun monitor deve essere creato.
2. Apri `/teams/3/notifications`; se viene chiesto di abbandonare i dati del modulo invalido scegli
   **Discard and leave**. In **Add a channel** scegli Email, lascia **Name** vuoto, scrivi
   `native-l02@example.test` in **Email address**, poi **Add channel**. Deve comparire l’errore sul
   nome con il campo identificabile da VoiceOver; nessun canale e nessuna email vengono creati
   da questo invio invalido. Registra separatamente errore visibile e annuncio.
3. Ripeti nei due browser. Ripristina VoiceOver allo stato iniziale; non salvare le bozze invalide.

## L03 — Zoom browser 200%

Usa il controllo **Zoom** del menu del browser e porta il valore mostrato a **200%**.
È lo zoom della pagina, non l’ingrandimento dello schermo macOS, la vista mobile, un ridimensionamento
finestra o CSS `zoom`. Annotare il numero visibile nel menu evita di dedurre il fattore da una scorciatoia.

Su entrambe le versioni dei browser controlla:

| Schermata | Percorso in questa installazione | Controlli da raggiungere |
|---|---|---|
| Dashboard | `/teams/3/dashboard` | Menu, conteggi, link monitor, grafici/tabelle equivalenti, Monthly reports |
| Lista monitor | `/teams/3/monitors` | Ricerca/filtri e scorrimento orizzontale locale della tabella |
| Dettaglio monitor | `/teams/3/monitors/7` | Azioni, schede, Monitor charts, periodi/regioni e View … data |
| Incidente risolto | `/teams/3/incidents/9` | Timeline e campi nota/severità/causa, senza inviare |
| Manutenzioni | `/teams/3/maintenance` | Filtri, Refresh, elenco o stato vuoto |
| Notifiche | `/teams/3/notifications` | Ricerca, Edit channel e Configure quiet hours, senza salvare |
| Consegne | `/teams/3/notifications/deliveries` | Filtri, Refresh ed elenco/stato vuoto |
| Status page private | `/teams/3/status-pages` | Ricerca ed Edit Ruoli Alpha, senza salvare |
| Team | `/teams/3` | Membri/inviti e Plan and usage, senza modificare ruoli |
| Account | `/account` | Menu account e link alle impostazioni |
| Pagina pubblica anonima | `/status/status-test` | Titolo, componenti/storico e modulo email, senza iscriversi |

Apri le sezioni comprimibili con Invio e naviga anche con Tab/Shift+Tab. Il testo deve restare
leggibile e i comandi raggiungibili; nelle tabelle può essere necessario scorrere orizzontalmente
la loro regione. Se appare **Open navigation**, aprilo e chiudilo con Escape, verificando il ritorno
del focus. Alla fine riporta il menu Zoom a **100%**, chiudi gli editor e lascia i dati invariati.

## Esito da restituire

Per ogni browser indica versione e:

- L02 monitor: errore visibile sì/no; focus su Name sì/no; annuncio spontaneo ascoltato, oppure assente.
- L02 canale: errore visibile sì/no; campo associato sì/no; annuncio spontaneo ascoltato, oppure assente.
- L03, soltanto se ripetuto volontariamente: menu Zoom a 200%, schermate controllate ed eventuali testi/comandi irraggiungibili.
- VoiceOver e zoom ripristinati; eventuali differenze tra Brave e Firefox.

Un errore soltanto visibile non è un PASS dell’annuncio. Una parte non osservata resta aperta;
non serve dichiarare superato il task per poter annotare risultati parziali.
