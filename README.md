# Luczor Admin API

Schlanke Laravel-10-Admin-App fuer Luczor:

- Fortify/Jetstream Auth mit Luczor Blade UI
- Custom Device API Keys
- verschluesselte Provider Credentials
- Modellprofile und priorisierte Fallback-Ketten pro Use-Case
- Sync-/Archiv-API fuer Projekte, Messages, Memories, Summaries und Agent Events

## Setup

```bash
composer install
npm install
php artisan key:generate
php artisan migrate:fresh --seed
npm run build
php artisan test
```

Default-Seed:

- Login: `admin@luczor.local`
- Passwort: `password`

## API

Basis: `/api/v1`

- `GET /health`
- `GET /bootstrap`
- `GET /model-profiles`
- `GET /runtime-settings`
- `POST /sync/push`
- `GET /sync/pull`
- `POST /agent-events`

Geschuetzte Endpunkte nutzen `Authorization: Bearer <token>` oder `X-Api-Key`.
