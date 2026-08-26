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

## Wiederaufnahme nach fehlgeschlagener Memory-Migration `000002`

Eine früh veröffentlichte Fassung von
`2026_08_23_000001_add_memory_orchestration_fields` enthielt noch keine Spalte
`memory_links.write_fingerprint`. Die Spalte war zwischenzeitlich irrtümlich in
dieselbe bereits auslieferbare Migration aufgenommen worden. Eine Installation,
auf der `000001` bereits als ausgeführt registriert ist, führt den geänderten
Dateiinhalt nicht erneut aus.

Der korrigierte Stand stellt den veröffentlichten Inhalt von `000001` wieder her.
Die lexikalisch danach und vor `000002` einsortierte Migration
`2026_08_23_000001_ensure_memory_write_fingerprint` ergänzt die nullable Spalte
nur, wenn sie fehlt. `000002` akzeptiert eine nach einem MySQL-DDL-Abbruch bereits
vorhandene `memory_write_events`-Tabelle ausschließlich dann, wenn Spalten,
Typen, Nullability, Default, Indizes und Fremdschlüssel vollständig dem erwarteten
Vertrag entsprechen; anschließend ist der Backfill wiederholbar.

Vor einem erneuten Produktionslauf:

1. Anwendung, Scheduler und Horizon vollständig in Wartung nehmen und ein
   wiederherstellbar geprüftes Datenbank-Backup erstellen.
2. Den korrigierten Code deployen. Weder `migrate:fresh` noch ein pauschales
   Rollback verwenden und `000002` nicht manuell in die Tabelle `migrations`
   eintragen.
3. Read-only prüfen:

   ```text
   php artisan migrate:status --no-ansi
   ```

   In der Datenbank zusätzlich Migrationseinträge, `memory_links.write_fingerprint`,
   `SHOW CREATE TABLE memory_write_events` und die Zeilenanzahl dieser Tabelle
   kontrollieren. Eine vorhandene Tabelle nicht ungeprüft löschen.
4. Beide stabilen Memory-Schlüssel sowie die übrige Produktionskonfiguration
   ohne Ausgabe ihrer Werte prüfen:

   ```text
   php artisan luczor:deployment-check --production --configuration-only
   ```

5. Erst danach die ausstehenden Migrationen einmalig und isoliert ausführen:

   ```text
   php artisan migrate --force --isolated
   php artisan migrate:status --no-ansi
   php artisan luczor:deployment-check --production
   ```

Der lokale Regressionstest bildet den veröffentlichten Altstand und die bereits
angelegte MySQL-Teiltabelle nach. Die tatsächliche Produktionstabelle muss vor
dem Retry trotzdem anhand der obigen read-only Evidenz bestätigt werden.
