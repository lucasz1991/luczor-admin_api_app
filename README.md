# Luczor Control Plane

Stichtag: 2026-09-06

Laravel-/Livewire-Control-Plane für Identität, Abilities, Policies, signierte Modellkataloge, Workflows, Gerätejobs, Notifications, Reverb, Sync/Audit und gemeinsames Memory. Die Desktop-App ist ein getrenntes System; Laravel hostet weder lokale Modellgewichte noch `llama.cpp`.

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

`migrate:fresh` ist kein normaler Setup- oder Deploymentschritt, weil es Daten löscht. Seed-Daten und lokale Zugänge müssen bewusst für die jeweilige Entwicklungsumgebung angelegt werden; es gibt keine Produktions-Standardzugänge.

## Zuständigkeit gegenüber dem Desktop

Laravel:

- authentisiert Benutzer, Device-Key und konkrete Ability;
- liefert Bootstrap, Runtime-Settings, Realtime-Konfiguration und signierte Manifeste;
- entscheidet serverseitig über Policies und den begrenzten externen Proxyweg;
- hält gemeinsame Memory-Daten kanonisch in SQL;
- betreibt Cognee nur als wiederaufbaubare semantische Projektion;
- koordiniert Workflows, Device-Jobs, Notifications und Audit.

Der Desktop:

- speichert Device-Key, private/local-only Erinnerungen und Repositorydaten lokal;
- verifiziert Modellmanifest und Hardwareeignung;
- besitzt GGUF, Runtime, Loopbacklistener und lokalen Prozess;
- assembliert aktuell den Chatkontext und holt nötige Freigaben ein; die strikte zielgetrennte Context-Broker-Verdrahtung bleibt offen.

## Lokaler Modellkatalog

`config/local_models.php` ist im Standard fail-closed. OrcaRouter Qwen3.8-27B Uncensored Q4_K_M ist der aktuelle lokale Test- und Hauptmodellkandidat; Qwen3.8 Flash-Next bleibt deaktiviertes Experiment. Beide Einträge sind standardmäßig `enabled=false` und enthalten keine ausführbaren Artefakt-/Runtimeangaben.

Laravel signiert Metadaten und Routingregeln. Ein ausführbarer lokaler Release benötigt einen vollständig ausgefüllten, höher versionierten Katalog mit Artefakt-, Runtime-, Capacity-, Health-, Template- und Evaluationsbindung. Ein externer Weg bleibt zustimmungs- und policy-pflichtig; es gibt keinen stillen Fallback.

## Isolierter Bootstrap für den Luczor-Modelltest

Der Artisan-Befehl:

```text
luczor:local-model-test:bootstrap
  --test-root=<absoluter dedizierter Ordner>
  --database-file=<absolute SQLite-Datei>
  --token-file=<absolute Ausgabedatei>
```

ist ausschließlich ein lokaler/Testing-Vertrag. Er verweigert die Ausführung, wenn:

- `APP_ENV` nicht `local` oder `testing` ist;
- die effektive Defaultverbindung nicht SQLite ist;
- die konfigurierte Datenbank kein existierendes On-Disk-SQLite ist;
- Testroot, Datenbank oder Tokenpfad relativ, UNC, checkout-intern, verlinkt, mehrdeutig oder fremd sind;
- Datenbank und Token keine direkten Kinder des dedizierten Testroots sind;
- das Root nicht leer beziehungsweise nicht durch den Befehl eindeutig markiert und besessen ist.

Der Befehl erzeugt beziehungsweise erneuert nur:

- eine dedizierte lokale Testbenutzeridentität;
- einen gerätegebundenen API-Key mit exakt `settings.read`;
- eine Gültigkeit von acht Stunden;
- Marker/Lock im dedizierten Testroot;
- den Klartext-Key ausschließlich in der ausdrücklich angegebenen geschützten Datei.

Er legt keine Produktprojekte, Settings, Devices oder Modelldatensätze an und ruft keine Seeder auf. Gleichnamige, nachweislich von derselben Testinstanz besessene Alt-Keys werden deaktiviert. Fehler führen zum Rollback beziehungsweise zum Entfernen oder Zurücksetzen der Tokenausgabe; der Key wird weder in Konsole noch Laravel-Log offengelegt.

Der kanonische Aufrufer ist `../app/scripts/run-local-model-test.ps1`. Er setzt zusätzlich ein isoliertes Test-Environment, externe Storage-/View-/Cachepfade, entfernt `DATABASE_URL` und `DB_URL`, prüft die effektive Laravel-Konfiguration vor `migrate` und räumt Umgebung, Config-Cache und Secretdateien anschließend wieder auf.

Direkte manuelle Verwendung ist nur in einem bewusst vorbereiteten Wegwerfroot zulässig. Niemals auf eine bestehende Entwicklungs- oder Produktionsdatenbank zeigen.

## API

Basis: `/api/v1`

- `GET /health` und `GET /ready` – minimale Liveness/Readiness;
- `GET /version` – Produkt-/API-Version ohne Framework-Fingerprinting;
- `GET /bootstrap`, `GET /runtime-settings`, `GET /local-model/manifest` – authentisierte Desktopsteuerung;
- `POST /sync/push`, `GET /sync/pull` – begrenzter Archiv-/Defaults-Sync;
- `POST /memory/remember|recall|promote|forget|improve` – Memory-Orchestrator;
- Context-, Projekt-, Task-, Conversation-, Agent-, Policy-, Workflow-, Device-, Notification-, MCP- und LLM-Endpunkte gemäß `routes/api.php`.

Geschützte Endpunkte akzeptieren einen Device-Key als Bearer-Token oder `X-Api-Key`. Middleware und Services prüfen Ability, Benutzer-/Projekt-/Geräteeigentum, Scope und Freigabe serverseitig.

## Memory und Cognee

Laravel SQL ist die kanonische gemeinsame Memory-Wahrheit. Automatisch/inferiert entstandene Inhalte bleiben Kandidaten bis zur Promotion. Aktive dauerhafte Daten tragen Provenienz, Confidence, Gültigkeit, Supersession, stabile Schreibidentität und Projektionsstatus.

Cognee erhält nur geeignete DLP-geprüfte Projektionen. Add, Cognify, Improve und Forget laufen über durable, deduplizierte und wiederanlaufbare Aufträge. Recall kombiniert SQL-Recency, vollständige chunked Lexical-Suche und SQL-revalidierte semantische Kandidaten; ein Cognee-Fehler fällt auf SQL zurück.

Im akzeptierten Plesk-Profil ist `COGNEE_IMPROVE_ENABLED=true` korrekt. Improve wird ausschließlich durch den expliziten, gedrosselten Improve-Aufruf gestartet. Normale Remember-Writes lösen Improve nicht aus.

## Asynchroner Betrieb

Produktiver Vollbetrieb benötigt:

- Redis mit Authentisierung und Persistenz;
- einen dauerhaft überwachten Horizon-Prozess;
- `php artisan schedule:work` oder einen minütlichen Scheduler-Aufruf;
- Reverb plus WebSocket-Proxy und konkrete Origins;
- HTTPS, sichere Cookies und getrennte Secretdateien;
- Überwachung von Failed Jobs, Queue-Latenz, Scheduler und Health.

`QUEUE_CONNECTION=sync` ist nur lokaler Fallback und kein produktiver Realtime-/Queue-Betrieb.

Der belegte Plesk-Stand ist revisionsgebunden: Commit `d771098` wurde am 2026-08-29 mit Migrationen, Production-Gates, Redis/Horizon/Scheduler, Login und lokalem Cognee-Memorypfad abgenommen. Spätere lokale Dirty-Worktree-Änderungen des Modelltest-Bootstraps sind dadurch nicht automatisch deployed oder freigegeben.

## Docker und Secrets

```powershell
.\docker\init-secrets.ps1
Copy-Item ..\.env.docker.example ..\.env.docker
docker compose --env-file ..\.env.docker config --quiet
docker compose --env-file ..\.env.docker --profile bootstrap run --rm migrate
docker compose --env-file ..\.env.docker up -d --build
```

Für lokale Entwicklung ist `docker/secrets` ignoriert. Produktion setzt `LUCZOR_DOCKER_SECRETS_DIR` auf einen absoluten, betreibergeschützten Pfad außerhalb des Checkouts. Secretwerte gehören weder in `.env`, Config-Cache, Prozessargumente noch Repository.

Der Bootstrap-Migrationslauf verändert die konfigurierte PostgreSQL-Datenbank. Ziel, Backup und Rollback müssen vorher feststehen. Eine Datenbankmigration zwischen Engines ist ein eigener kontrollierter Ablauf.

## Qualitätsprüfungen

```powershell
php artisan test
vendor\bin\pint --test
vendor\bin\phpstan analyse
composer audit
npm run build
npm audit --omit=dev
```

Am 2026-09-05 waren im damaligen Dirty-Worktree-Stand 435 Laravel-Tests / 3.054 Assertions sowie 29 fokussierte Bootstrap-/API-Key-/Manifesttests / 242 Assertions grün; PHPStan, Pint und Diffcheck bestanden. Nach dem ersten Vollrunner-Fehler vor Modellstart wurde am 2026-09-06 die Behandlung absoluter Windows-Cachepfade in `bootstrap/app.php` korrigiert und mit einem echten `Filesystem::replace`-Test (1 Test / 3 Assertions) verifiziert. Da diese Änderung nach der großen Suite entstand, benötigt sie noch die erneute Gesamtabnahme. Ein bestandener Laravel-Test belegt weder einen echten lokalen Modelllauf noch den weiterhin offenen vollständigen Luczor-App-E2E.

Deploymenthinweise stehen unter `docs/`, insbesondere `docs/plesk-git-deployment.md`. Der workspaceweite Stand liegt in `../README.md`.
