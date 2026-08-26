# Luczor Backend: Deployment-Readiness

Diese Prüfung trennt den lokalen Code- und Testzustand vom tatsächlichen Zustand einer
Produktionsumgebung. Sie führt keine Migration und keine Prozessänderung selbst aus.

## Konfigurationsprüfung in CI oder vor dem Rollout

```text
php artisan luczor:deployment-check --production --configuration-only
```

Geprüft werden deaktiviertes Debugging, HTTPS, Secure-Cookies, explizite CORS- und
Reverb-Origins, Redis als Queue, die Horizon-Queue-Liste sowie vollständige
Reverb-Credentials. Auch der Cache muss Redis verwenden, damit Scheduler-Heartbeat und
verteilte Locks für Web- und Worker-Prozesse identisch sichtbar sind. Geheimnisse werden
nicht ausgegeben.

## Vollständige Prüfung auf dem Zielserver

```text
php artisan luczor:deployment-check --production
```

Zusätzlich geprüft werden:

- Datenbankverbindung und ausstehende Migrationen,
- Redis-Ping und ein laufender, nicht pausierter Horizon-Master,
- ein höchstens drei Minuten alter Scheduler-Heartbeat,
- eine TCP-Verbindung zum internen Reverb-Listener.

Für eine reine Diagnose ohne Socket-Probe kann `--skip-reverb-probe` verwendet werden.
Das ersetzt nicht den WebSocket-End-to-End-Test über den Reverse Proxy.

## HTTP-Probes

- `GET /api/v1/health`: schlanker Liveness-Check für Datenbank und gegebenenfalls Redis.
- `GET /api/v1/ready`: Readiness-Check; in Produktion nur `200`, wenn auch Migrationen,
  Queue, Horizon, Scheduler und die Sicherheitskonfiguration einsatzbereit sind.
- `GET /api/v1/version`: zeigt nur Produkt-/API-Version und Serverzeit, keine PHP- oder
  Laravel-Version.

Der Load-Balancer darf Traffic erst nach einem erfolgreichen Readiness-Check zuschalten.
Die konkreten Plesk-/Reverb-Schritte stehen in `docs/push-notifications-plesk.md`.

## Betriebs- und Desktop-Gesamtabnahme

Die netzlose lokale Vorprüfung und das fail-closed Evidence-Format für Migration,
Redis/Horizon/Scheduler/Reverb, gepackte GUI, Keychain, Audio, Notifications sowie
Updater-/Plattform-Signing sind in `docs/operations-acceptance.md` beschrieben. Der
zugehörige Runner ist:

```text
php artisan luczor:operations-acceptance --workspace-root=.. --local-only
```

Ohne explizite, revisionsgebundene Evidence bestätigt dieser Befehl keine
Produktionsbereitschaft.
