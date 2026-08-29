# Luczor Control Plane

Laravel-/Livewire-Backend für Authentisierung, Admin-Steuerung, Modellrouting, Workflows, Gerätejobs, persistente Notifications, Reverb, Sync-Archive und Auditdaten.

## Lokale Entwicklung

```powershell
composer install
npm ci
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
npm run build
```

`migrate:fresh` ist kein normaler Setup- oder Deployment-Schritt, weil es vorhandene Daten löscht. Seed-Daten und lokale Zugänge müssen bewusst für die jeweilige Entwicklungsumgebung erzeugt werden; es gibt keine dokumentierten Produktions-Standardzugänge.

## Asynchroner Vollbetrieb

Für Workflows, Gerätejobs, Notifications und Realtime-Broadcasting benötigt die Produktionsumgebung:

- Redis und einen dauerhaften Horizon-Prozess
- `php artisan schedule:work` oder einen minütlichen Scheduler-Aufruf
- einen dauerhaften Reverb-Prozess plus WebSocket-Proxy
- konkrete CORS- und Reverb-Origins
- HTTPS und Secure-Session-Cookies
- überwachte Failed Jobs, Queue-Latenz und Prozess-Healthchecks

`QUEUE_CONNECTION=sync` ist ausschließlich ein lokaler Fallback und kein produktionsfähiger Realtime-/Queue-Betrieb.

## Docker

```powershell
.\docker\init-secrets.ps1
Copy-Item ..\.env.docker.example ..\.env.docker
docker compose --env-file ..\.env.docker config --quiet
docker compose --env-file ..\.env.docker --profile bootstrap run --rm migrate
docker compose --env-file ..\.env.docker up -d --build
```

Für lokale Entwicklung verwendet der Initialisierer standardmäßig den vollständig
ignorierten Ordner `docker/secrets`. In Produktion muss
`LUCZOR_DOCKER_SECRETS_DIR` auf einen absoluten, nur für den Betreiber lesbaren
Pfad außerhalb des Git-Checkouts zeigen, beispielsweise
`/var/lib/luczor/secrets`. Compose, beide Initialisierer und die Cognee-
Provisionierung verwenden dadurch garantiert dieselbe Quelle; Secret-Werte
gehören weder in `.env` noch in das Repository.

Der Bootstrap-Migrationslauf verändert die konfigurierte PostgreSQL-Datenbank. Vorher Ziel, Backup und Rollback prüfen. Die MySQL-zu-PostgreSQL-Übernahme erfolgt separat über den vorhandenen Migration Assistant und erst nach Prüfung seines Manifests.

## API

Basis: `/api/v1`

- `GET /health` – öffentlicher, minimaler Liveness-Check
- `GET /version` – öffentliche Produkt-/API-Version ohne Framework-Fingerprinting
- `GET /bootstrap` – authentisierte Laufzeitkonfiguration
- `POST /sync/push` / `GET /sync/pull` – begrenzter, paginierter Archiv-Sync
- Device-, Workflow-, Notification-, Memory-, Context- und Modellendpunkte gemäß `routes/api.php`

Geschützte Endpunkte akzeptieren einen Device-Key als Bearer-Token oder `X-Api-Key`. Abilities, Benutzer-/Projekt-/Geräteeigentum und Freigaben werden serverseitig geprüft.

## Qualitätsprüfungen

```powershell
php artisan test
vendor\bin\pint --test
vendor\bin\phpstan analyse
composer audit
npm run build
npm audit --omit=dev
```

Deployment- und Notification-Hinweise stehen unter `docs/`. Für die Trennung von
Plesk-Git-Quellen und Runtime-Dateien gilt zusätzlich
[`docs/plesk-git-deployment.md`](docs/plesk-git-deployment.md). Der Workspace-
übergreifende Einstieg liegt in `../README.md`.
