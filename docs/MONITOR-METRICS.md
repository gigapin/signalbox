# Proiezioni private di dashboard e monitor

UI.03.1 estende le letture, senza cambiare check, incidenti, notifiche, schema o formule SLA. La dashboard conserva `DashboardOverview`/`DashboardLatency` e gli URL con selezione monitor/pagina. Le nuove schede/grafici entrano nei sotto-task successivi: la presenza delle props non ne certifica già la resa grafica.

## Contratti e fonti

`GET /teams/{team}/monitors/{monitor}` autorizza con `ShowMonitorRequest` e il monitor della rotta. Le query non scelgono tenant o monitor; `period` ammette `24h`, `7d`, `30d`, `region` ammette `all`, `eu`, `us`. Default 24h/all; valori malformati e array non causano redirect e restituiscono solo i nomi in `rejectedMetricFilters`. Nessun intervallo arbitrario o superiore a trenta giorni. Le policy e il middleware tenant esistenti restano autorevoli; anche il servizio composito rifiuta team/contesto non corrispondenti.

La prop `metrics`, composta da [MonitorMetrics](../app/Services/Monitoring/ReadModels/MonitorMetrics.php), contiene:

- `generatedAt`, `timezone: UTC`, `historyDays` del piano effettivo e `selection` accettata.
- `periods.24h/7d/30d`: estremi UTC semiaperti, `available`, uptime osservato, copertura, SLA e durate Up/Down/Unknown/Excluded in secondi con frazioni. Le durate sono limitate alla vita del monitor; nessuna media delle percentuali dei monitor o delle ore.
- `recent`: ventiquattro ore mobili con le stesse quattro durate e rapporti. Un’ora parzialmente osservata conserva tempo sconosciuto/escluso; non è una barra verde piena implicita.
- `latency`: disponibilità, `source: raw`, statistiche regionali dell’intera finestra in `summary` e serie di 24 ore oppure 7/30 giorni mobili. Ogni punto ha `startsAt/endsAt`, EU/US, media in ms, p50/p95 nearest-rank e numero di campioni misurati. Zero ms è distinto da null; i risultati senza latenza non entrano nei campioni. La regione esclusa mantiene campioni zero e statistiche null: leggere anche `selection`.

SLA/copertura riusano esclusivamente `MonitorAvailability`: rollup per ore intere, grezzo per bordi o ore non ancora aggregate, esclusioni e dati non ricostruibili secondo ADR-008/009. Il totale 24h combina i report delle barre già letti. Un periodo non ricostruibile ha durate/rapporti null; nessuna osservazione dà uptime null, non 100%. Il filtro regionale riguarda la latenza, mai il verdetto concordato di disponibilità.

[MonitorMetricLatency](../app/Services/Monitoring/ReadModels/MonitorMetricLatency.php) aggrega in PostgreSQL le osservazioni retained raw: slot nel range, registrazione e osservazione non posteriori alla fine della finestra, tenant e monitor espliciti. `GROUPING SETS` produce punti e statistiche complessive dalla stessa distribuzione; non media medie o percentili. Al massimo **62 righe SQL** (30 bucket × 2 regioni + 2 riepiloghi), senza caricare risultati grezzi in React o idratare ogni campione in PHP. Le righe esaminate nel database crescono con la cadenza: l’output limitato non è un costo O(1) sul volume.

Decisione: le statistiche regionali 7/30 giorni leggono il grezzo entro i trenta giorni logici e la disponibilità fisica delle partizioni. I rollup attuali fondono le regioni; usarli per percentili regionali inventerebbe la distribuzione richiesta. Non si cambiano rollup o retention per questo contratto. La storia leggibile dal piano si applica anche se i dati sono ancora presenti fisicamente. Nessun grafico regionale oltre 30 giorni promesso; un’estensione richiederà un contratto/fonte appropriati. Assenza di campioni, storia indisponibile e regione non selezionata vanno presentate distintamente.

La prop `monitorContext`, composta da [MonitorContext](../app/Services/Monitoring/ReadModels/MonitorContext.php), contiene:

- Certificato: `not_applicable` per monitor non TLS, `missing` senza expiry, altrimenti expiry cached e `observedAt` soltanto da un risultato retained della revisione corrente con la stessa expiry verificata. `current` significa osservato negli ultimi due intervalli configurati; fuori soglia o senza provenienza è `stale`. Ultimo check e `ssl_expires_at` da soli non attestano freschezza. Stale non significa scaduto; null observedAt non inventa una data. Nessuna scadenza della registrazione dominio.
- Notifiche: resolver esistente override/default/none, policy e step con soli ID, nomi, tipo e flag active/verified/stub dei canali del team. Nessuna configurazione, indirizzo, token, segreto o garanzia di consegna: quiet hours, manutenzione e policy restano operative. Le props di gestione escalation esistenti restano compatibili.

Manutenzione attuale/prossima e cinque incidenti recenti sono già proiettati dal controller e conservano i loro contratti. Le nuove props sono closure Inertia: letture parziali che le omettono non eseguono le nuove query. Il recupero completo della pagina le rilegge; la presentazione e i successivi adattamenti realtime sono UI.03.4–UI.03.8. Tipi TypeScript in [metrics.ts](../resources/js/components/monitors/metrics.ts).

La lista monitor conserva Scout, filtri e pagine da venti. Seleziona esplicitamente solo colonne riepilogo/destinazione; [MonitorDestination](../app/Services/Monitoring/ReadModels/MonitorDestination.php) decritta soltanto l’URL della pagina visibile e applica subito SecretRedactor. Header, body e keyword non vengono caricati per la lista. `destination` è una stringa sicura o null per heartbeat; dashboard/eventi mantengono riepiloghi senza tale campo. Nessuna nuova query per ogni riga.

## Misure riproducibili — 2026-11-10

Esecuzione su PHP **8.4.27**, PostgreSQL **16** nei servizi test Docker isolati. Clock `2026-11-10T12:00:00Z`, piano Business, nessun accesso esterno. [MonitorProjectionBenchmarkTest](../tests/Integration/MonitorProjectionBenchmarkTest.php) esclude le scritture fixture, riscalda ogni URL ed esegue tre richieste; il tempo HTTP è la mediana, il tempo SQL la somma dell’ultima richiesta misurata (non una mediana SQL). Le fixture vengono rollbackate; le partizioni del clock fisso sono assicurate esplicitamente, anche quando le migration partono da un mese reale successivo. Tempi locali indicativi, non benchmark di produzione o soglie CI; altri processi possono influenzarli.

```sh
docker compose run --rm -e RUN_PROJECTION_BENCHMARK=1 php-test php vendor/bin/pest tests/Integration/MonitorProjectionBenchmarkTest.php
```

Baseline raccolta **prima** delle modifiche: 1 e poi 40 monitor, nessun risultato/rollup, una riga di dettaglio o massimo venti righe lista. La seconda colonna di tempi riguarda la ripetizione finale con le nuove props, senza attribuire differenze a un’ottimizzazione non misurata. Le pagine prima/dopo hanno contenuto diverso nel dettaglio.

| Lettura | Monitor nel team | Query prima → dopo | Righe monitor proiettate | HTTP mediana prima → dopo, ms |
|---|---:|---:|---:|---:|
| Dashboard | 1 | 15 → 15 | 1 | 15.98 → 19.17 |
| Dashboard | 40 | 15 → 15 | 20 | 15.39 → 21.62 |
| Lista | 1 | 10 → 10 | 1 | 10.56 → 12.45 |
| Lista | 40 | 10 → 10 | 20 | 11.33 → 15.13 |
| Dettaglio | 1 | 16 → 36 | 1 | 13.06 → 46.16 |
| Dettaglio | 40 | 16 → 36 | 1 | 13.72 → 45.11 |

Il test richiede stesso numero di query con 1/40 monitor e al massimo venti righe: nessun N+1 introdotto su queste letture. Dashboard/lista non eseguono nuove metriche per ogni monitor. Le venti query aggiunte al dettaglio leggono tre finestre/barre e il contesto notifiche; non sono una riduzione del costo preesistente.

Seconda fixture: un monitor nato 32 giorni prima, **1440 risultati** (una osservazione/ora/regione per 30 giorni, EU 10 ms / US 100 ms), controllo `period=30d`, prima senza rollup e poi con **720 rollup orari** prodotti da RollupSource/HourlyRollupCalculator/RollupStore. Stesse osservazioni e stessi percentili in entrambi i casi:

| Stato aggregazioni | Query HTTP | Righe SQL latenza | HTTP mediana ms | Somma SQL ultima richiesta ms | EXPLAIN ANALYZE latenza ms |
|---|---:|---:|---:|---:|---:|
| Nessun rollup, fallback grezzo SLA | 39 | 62 | 379.68 | 352.59 | 1.98 |
| Rollup orari disponibili | 31 | 62 | 46.08 | 22.29 | 1.98 |

Il piano SQL realmente eseguito conferma 62 righe aggregate, non 62 campioni sorgente. La fixture non rappresenta la cadenza massima o migliaia di monitor. **Limite osservato:** il fallback SLA su storia lunga senza rollup costa sensibilmente di più; mantenere il processo di aggregazione operativo. Non introdotti cache, indici, formule alternative o risultati simulati per mascherarlo. Prove di carico alla cadenza massima e benchmark di consegna restano distinti; questi numeri non li sostituiscono.

## Verifiche

[MonitorMetricProjectionTest](../tests/Integration/MonitorMetricProjectionTest.php) copre dati mancanti/zero, distribuzioni diseguali, regioni, finestre semiaperte, risultati futuri/esteri, piano/retention/vita monitor, rollup e manutenzioni, filtri malformati, viewer/non membro, letture parziali, provenienza TLS e ciphertext canali deliberatamente illeggibile. Le suite pagine/SLA/rollup/notifiche/autorizzazioni e i controlli finali sono registrati nel [piano](TASKS.md). Nessun nuovo flusso visivo o PASS browser/L in UI.03.1; UI.03.2 prosegue con l’overview team.

## Adeguamento overview — UI.03.2

Il controller aggiunge `dashboard.can.create` e `dashboard.usage` dal Gate e da MonitorLimits, nello stesso root riletto da useLivePage. Il servizio metrico resta invariato. La precedente tabella di misure UI.03.1 è storica: dopo questo adeguamento il benchmark registra **18 query** dashboard sia con 1 sia con 40 monitor (prima 15), massimo venti righe proiettate; mediane locali 23.41/24.79 ms. Lista/dettaglio conservano rispettivamente 10/36 query sulla fixture senza risultati. Ripetizione con lo stesso comando e fixture, non un’ottimizzazione né una nuova attestazione di carico. Tempi dell’ulteriore prova con 1440 risultati: fallback grezzo 410.62 ms, rollup 97.79 ms; le fluttuazioni locali non modificano i limiti dichiarati sopra. Le nuove proprietà sono hint aggiornati, le mutazioni ricontrollano sempre policy/quota sotto lock.

## Presentazione della disponibilità — UI.03.4

Monitors/Show usa ora `metrics.periods/recent/generatedAt` per schede 24h/7d/30d, barre 24h e timestamp della proiezione. I rapporti restano quelli del servizio; solo proporzioni visive delle durate e formattazione hanno luogo nel client. La parte della finestra non appartenente alla vita del monitor è mostrata come Not monitored e non come tempo sconosciuto; un report non ricostruibile non viene parzialmente stimato. La tabella equivalente offre tutti i valori in secondi con frazioni, finestre UTC ed etichette esplicite.

La partial read del dettaglio include ora `metrics` insieme ai root esistenti monitor/recentIncidents/maintenance/escalation/can, seguiti da team/auth/realtime. Nessun nuovo endpoint, cache, formula o motore di calcolo; la closure metrica già presente viene eseguita anche nel recupero del dettaglio che adesso la visualizza. Il costo noto del fallback grezzo senza rollup resta applicabile; non sono nuove misure di prestazioni. La matrice backend verifica il nuovo protocollo senza esporre monitorContext nella partial read che lo omette. Le prove di UI.03.1 rimangono storiche.

Gli stati operativo/osservato e manutenzione sono separati in MonitorSnapshot. Il valore lastCheckedAt mancante non consente di presentare Up, e nextCheckAt non è mostrato come lavoro in esecuzione su un monitor paused/suspended. Grafici di latenza e controlli regione/periodo sono ancora UI.03.5; provenienza TLS e target notifiche UI.03.6.
