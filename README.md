# Signalbox

SaaS multi-tenant di monitoraggio applicativo e status page. Stato attuale: bootstrap Laravel 13 con Inertia 2, React 19, TypeScript e Tailwind 4; le funzionalità di dominio devono ancora essere implementate.

Il piano completo è in [docs/TASKS.md](docs/TASKS.md), le regole in [AGENTS.md](AGENTS.md) e le versioni in [docs/DEPENDENCIES.md](docs/DEPENDENCIES.md). La configurazione iniziale è documentata in [docs/BOOTSTRAP.md](docs/BOOTSTRAP.md).

## Avvio con Docker

Il setup completo, i servizi, le verifiche e il reset locale sono documentati in [docs/DOCKER.md](docs/DOCKER.md). Usare questo percorso per PHP 8.4, PostgreSQL 16 e Redis 7 senza installare il runtime sul computer.

## Avvio locale senza container

Requisiti: PHP 8.4 o 8.5 con le estensioni richieste da Composer, Composer 2, Node 22 (almeno 22.12), npm 10. PostgreSQL e Redis sono predisposti tramite Compose; questo avvio senza container mantiene sessioni/cache su file e code sincrone come da `.env.example`.

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

```sh
composer test
composer format:check
composer analyse
npm run types:check
npm run build
```

`composer format` applica la formattazione PHP. I test iniziali verificano HTML, protocollo Inertia, liveness e 404 senza database o Redis. I test infrastrutturali PostgreSQL/Redis sono disponibili esplicitamente in Docker, come descritto in `docs/DOCKER.md`; verifiche di dominio e frontend saranno aggiunte nelle rispettive milestone. `/up` indica soltanto che il framework si avvia: il controllo di readiness dell'infrastruttura appartiene a M11.

Non eseguire ancora migration su database di produzione: lo schema importato è quello di base del framework, da estendere con il dominio. `.env`, dipendenze, build, cache e log sono esclusi da Git.
