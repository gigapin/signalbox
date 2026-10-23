# Signalbox

SaaS multi-tenant di monitoraggio applicativo e status page. Stato attuale: bootstrap Laravel 13 con Inertia 2, React 19, TypeScript e Tailwind 4; M0 completata, con bootstrap da checkout pulito e verifiche finali superate il 2026-10-19. M1.01 introduce utenti, team e membership; M1.02 aggiunge registrazione, accesso, recupero password, verifica email e 2FA facoltativa con UI. Dettagli in [docs/IDENTITY.md](docs/IDENTITY.md) e [docs/AUTHENTICATION.md](docs/AUTHENTICATION.md). M1.03 aggiunge inviti, gestione membri, trasferimento della titolarità e cambio team: [docs/TEAMS.md](docs/TEAMS.md). M1.04 introduce contesto tenant, TeamScope e policy team/inviti: [docs/TENANCY.md](docs/TENANCY.md). M1.05 completa la matrice parametrica di isolamento: [docs/AUTHORIZATION-MATRIX.md](docs/AUTHORIZATION-MATRIX.md). M1 completata. M2.01 introduce il modello Monitor e le configurazioni tipizzate dei cinque tipi: [docs/MONITORS.md](docs/MONITORS.md). M2.02 aggiunge validazione per tipo, Form Request e patch esplicite con protezione dei segreti negli errori: [docs/MONITOR-INPUT.md](docs/MONITOR-INPUT.md). M2.03 introduce il confine SSRF condiviso (policy indirizzi, pinning DNS, redirect) e la redazione dei segreti in log e output: [docs/OUTBOUND-SECURITY.md](docs/OUTBOUND-SECURITY.md). M2.04 aggiunge creazione, modifica, eliminazione, pausa/ripresa dei monitor con policy per ruolo e limiti di piano centralizzati sotto lock: [docs/MONITOR-CRUD.md](docs/MONITOR-CRUD.md). M2.05 aggiunge le pagine lista/dettaglio/form, la ricerca Scout sulle colonne in chiaro, i filtri e la paginazione accessibili: [docs/MONITOR-UI.md](docs/MONITOR-UI.md). M2.06 completa la copertura integrata (tutti i tipi, input errati, permessi, isolamento, sesto monitor Free rifiutato con richieste concorrenti al server HTTP reale) e chiude M2. La milestone MA, fuori dalla specifica, aggiunge gli operatori di piattaforma (concessi solo da CLI) e un pannello `/admin` per assegnare ai team un test illimitato o un piano omaggio: [docs/PLATFORM-ADMIN.md](docs/PLATFORM-ADMIN.md). M3.01–M3.02 aggiungono scheduling con jitter, registro transazionale e consegna recuperabile, disabilitati fino al runner reale: [docs/MONITOR-SCHEDULING.md](docs/MONITOR-SCHEDULING.md). I checker restano nei task successivi.

Il piano completo è in [docs/TASKS.md](docs/TASKS.md), le regole in [AGENTS.md](AGENTS.md) e le versioni in [docs/DEPENDENCIES.md](docs/DEPENDENCIES.md). La configurazione iniziale è documentata in [docs/BOOTSTRAP.md](docs/BOOTSTRAP.md); i pacchetti applicativi in [docs/SERVICES.md](docs/SERVICES.md).

Le [decisioni architetturali](docs/ARCHITECTURE.md) definiscono confini del dominio, checker Strategy, contesto tenant, scheduling recuperabile, conferma regionale, retention, SLA, piani e convenzioni di documentazione, distinguendo le scelte adottate dalle funzionalità ancora da implementare.

## Avvio con Docker

Il setup completo, i servizi, le verifiche e il reset locale sono documentati in [docs/DOCKER.md](docs/DOCKER.md). Usare questo percorso per PHP 8.4, PostgreSQL 16 e Redis 7 senza installare il runtime sul computer.

Horizon gestisce ora le code; `/horizon` restituisce `403` finché non è autenticato un operatore verificato e autorizzato. Per aggiornare un ambiente Docker esistente dopo M0.04, seguire [docs/SERVICES.md](docs/SERVICES.md).

## Avvio locale senza container

Requisiti: PHP 8.4 o 8.5 con le estensioni richieste da Composer, Composer 2, Node 22 (almeno 22.22.2), npm 10. PostgreSQL e Redis sono predisposti tramite Compose; questo avvio senza container mantiene sessioni/cache su file e code sincrone come da `.env.example`.

```sh
composer install
npm ci
cp .env.example .env
php artisan key:generate
npm run build
composer run dev
```

Aprire http://127.0.0.1:8000. Per lo sviluppo frontend con aggiornamenti live, eseguire `npm run dev` in un secondo terminale; il server PHP deve restare attivo. Il comando di generazione chiave si esegue soltanto alla prima configurazione di `.env`.

## Verifiche locali

Verifica completa con database e Redis dedicati ai test, senza avviare l’applicazione:

```sh
./scripts/verify.sh
```

Preparazione, comandi e protezioni sono in [docs/TESTING.md](docs/TESTING.md). Per eseguire le parti singolarmente:


```sh
composer test
composer format:check
composer analyse
npm run verify
```

`composer format` applica la formattazione PHP; `npm run format` quella frontend. `npm run check` verifica lint, formato, TypeScript e i cinque test degli strumenti di qualità. Configurazione e valutazione di Rector sono in [docs/QUALITY.md](docs/QUALITY.md). I test iniziali verificano HTML, protocollo Inertia, liveness e 404 senza database o Redis. I test infrastrutturali PostgreSQL/Redis sono disponibili esplicitamente in Docker, come descritto in `docs/DOCKER.md`; i primi test frontend DOM sono operativi e le verifiche di dominio saranno aggiunte nelle rispettive milestone. `/up` indica soltanto che il framework si avvia: il controllo di readiness dell'infrastruttura appartiene a M11.

Non eseguire ancora migration su database di produzione: lo schema comprende le prime entità di identità, mentre il dominio e le autorizzazioni sono ancora in sviluppo. `.env`, dipendenze, build, cache e log sono esclusi da Git.

## GitHub Actions

La [pipeline CI](docs/CI.md) esegue backend PHP 8.4 e frontend Node 22 su tutte le pull request e sui push a staging/main. Il backend PHP 8.5 viene verificato soltanto sui push a main e sugli avvii manuali su main. I job backend usano PostgreSQL 16 e Redis 7 dedicati; formatter, analisi, test e build hanno step distinti. Gli esiti locali e la conferma utente dei controlli remoti sono registrati in [docs/TASKS.md](docs/TASKS.md).
