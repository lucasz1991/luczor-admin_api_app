# Luczor Push-Benachrichtigungen auf Plesk

Luczor verwendet dieselben bewährten Produktmuster wie RailTime: ausdrückliches Opt-in,
Kategorien, stabile Ereignis-IDs, asynchrone Zustellung und eine private, je Gerät
authentifizierte Verbindung. Da Luxor eine Tauri-Desktop-App ist, übernimmt Reverb den
Live-Transport und eine native Desktop-Bridge die Betriebssystem-Benachrichtigung.

## 1. Produktionswerte

In der produktiven `.env` des Laravel-Backends:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://luczor.follow-flow.de
SESSION_SECURE_COOKIE=true

CORS_ALLOWED_ORIGINS=https://luczor.follow-flow.de,http://tauri.localhost,https://tauri.localhost,tauri://localhost
REVERB_ALLOWED_ORIGINS=luczor.follow-flow.de,tauri.localhost,localhost

BROADCAST_DRIVER=reverb
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
LUCZOR_NOTIFICATION_QUEUE=notifications
HORIZON_QUEUES=notifications,default
HORIZON_TRIES=4
HORIZON_TIMEOUT=90

REVERB_APP_ID=luczor
REVERB_APP_KEY=<oeffentlicher-zufaelliger-key>
REVERB_APP_SECRET=<geheimer-zufaelliger-key>

REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8082
REVERB_HOST=luczor.follow-flow.de
REVERB_PORT=443
REVERB_SCHEME=https

LUCZOR_REVERB_PUBLIC_HOST=luczor.follow-flow.de
LUCZOR_REVERB_PUBLIC_PORT=443
LUCZOR_REVERB_PUBLIC_SCHEME=https
```

`REVERB_APP_SECRET` bleibt ausschließlich auf dem Server. Die Tauri-App erhält über
`GET /api/v1/realtime/config` nur den öffentlichen Key und authentifiziert ihren privaten
Kanal anschließend mit einer kurzlebigen Geräte-Session.

Die Origin-Listen sind absichtlich explizit. CORS erwartet vollständige Origins,
Reverb dagegen Hostnamen aus dem geparsten `Origin`-Header. Nicht benötigte
Tauri-Ursprünge müssen aus der Liste entfernt werden; `*` ist weder für CORS noch für
Reverb zulässig.

## 2. Dauerhafte Prozesse und Scheduler

Für die Domain müssen Reverb und Horizon dauerhaft unter dem Eigentümer des vHosts
laufen:

```text
php artisan reverb:start --host=127.0.0.1 --port=8082
php artisan horizon
```

Auf dem geprüften gemeinsamen Plesk-Host ist 8080 bereits RailTime und 8081 Factory
zugeordnet. Luczor verwendet ausschließlich den eigenen Loopback-Port 8082. Auf einem
anderen Host zuerst die Portbelegung und den Prozessbesitzer prüfen, nicht blind
Beispielwerte übernehmen.

In Plesk können diese über Supervisor oder systemd verwaltet werden. Beide Prozesse
sollen automatisch starten und nach einem Fehler neu gestartet werden. Zusätzlich ruft
ein einzelner Cronjob pro Minute den Laravel-Scheduler auf:

```cron
* * * * * cd /var/www/vhosts/<vhost>/httpdocs && php artisan schedule:run >> /dev/null 2>&1
```

Der Scheduler aktualisiert einen Heartbeat und treibt die Workflow-Fortschreibung an.
Ein zweiter `schedule:run`-Cronjob würde Jobs doppelt einplanen.

Aktueller Luczor-Betrieb: Plesk-Aufgabe 61 hält Reverb unter dem Subscription-Benutzer
mit `flock` als Einzelinstanz; jede Minute wird bei freier Sperre ein Start versucht.
Nach Prozessende oder Reboot kann die Wiederherstellung bis zur nächsten Minute dauern.
Das ist ein Cron-Watchdog, kein systemd-Dienst. Toolkit-Scheduler 58 bleibt aktiv,
der identische manuelle Scheduler 59 ist deaktiviert. Diese IDs gelten nur für diesen
Plesk-Host; vor Änderungen aktuelle Aufgaben prüfen.

## 3. Kontrolliertes Deployment

Die Notification-Migration erzeugt ausschließlich neue Tabellen. Zusätzlich werden die
Laravel-Tabellen für Queue-Batches und fehlgeschlagene Jobs angelegt. Diese Migrationen
wurden bei der Implementierung **nicht** gegen eine produktive Datenbank ausgeführt.

Vor dem Umschalten des Traffics:

```text
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
php artisan horizon:terminate
php artisan reverb:restart
php artisan luczor:deployment-check --production
```

Vor `migrate --force` ist ein aktuelles, wiederherstellbar getestetes Datenbank-Backup
Pflicht. Supervisor startet Horizon nach `horizon:terminate` mit der neuen Version neu.
Der Deployment-Check schlägt fehl, wenn Migrationen offen sind, Redis/Horizon/Reverb
nicht erreichbar sind, der Scheduler-Heartbeat fehlt, die Queue synchron läuft oder
Origins/Cookies unsicher konfiguriert sind. `/api/v1/ready` liefert denselben
Readiness-Zustand ohne Geheimnisse oder Frameworkversionen aus.

## 4. WebSocket-Proxy

Beide Reverb-Pfade müssen zum **Luczor**-Listener weitergeleitet werden: `/app/`
für WebSockets und `/apps/` für signierte Publish-Anfragen. Ein laufender Redis-Worker
oder ein offener TCP-Port beweist diese Weiterleitung nicht. Der öffentliche
Client-Endpunkt ist `wss://luczor.follow-flow.de`.

Am geprüften Ziel werden die zusätzlichen Apache-Direktiven der Luczor-Domain
verwendet (nicht eine globale nginx-Regel). Für Apache 2.4.47+:

```apache
ProxyPass "/app/" "http://127.0.0.1:8082/app/" upgrade=websocket retry=0 timeout=75
ProxyPassReverse "/app/" "http://127.0.0.1:8082/app/"
ProxyPass "/apps/" "http://127.0.0.1:8082/apps/" retry=0 timeout=15
ProxyPassReverse "/apps/" "http://127.0.0.1:8082/apps/"
```

Die Regeln stehen in den HTTP- und HTTPS-Zusatzfeldern dieser Domain; vorhandene
Direktiven vor Änderungen sichern und erhalten. TLS bleibt am öffentlichen Webserver,
Reverb bindet nur an Loopback. Keine Wildcard-Origins und kein `upgrade=ANY` verwenden.
Grundlagen: [Laravel Reverb](https://laravel.com/framework/docs/12.x/reverb),
[Apache WebSocket Upgrade](https://httpd.apache.org/docs/2.4/mod/mod_proxy_wstunnel.html).

Live-Abnahme am 2026-09-15: synthetischer Redis-Broadcast über Horizon an Reverb
erfolgreich; öffentlicher TLS-WebSocket mit gültigem HTTP-101-Handshake und
`pusher:connection_established` erfolgreich. Testkonten, Geräte und Aufträge danach
getrennt als entfernt geprüft. Dies ersetzt nicht die folgende Zwei-Geräte-Abnahme.

## 5. Abnahmetest

1. Tauri-App installieren und mit einem Device-Key mit `device.connect` anmelden.
2. Unter Einstellungen → Benachrichtigungen Push ausdrücklich aktivieren.
3. Eine Geräteaufgabe abschließen oder einen Root-Workflow in einen Endstatus bringen.
4. Prüfen, dass genau eine native Meldung erscheint.
5. Luczor über das Fenster-X in den Tray schließen und den Test wiederholen.
6. Reverb kurz stoppen, ein Ereignis erzeugen, Reverb starten und Luczor fokussieren.
   Die gespeicherte Meldung muss über den REST-Cursor nachgeholt werden.
7. Die Meldung anklicken. Luczor muss fokussiert, die Meldung als gelesen markiert und
   die Benachrichtigungsansicht geöffnet werden.
8. `php artisan luczor:deployment-check --production` und `GET /api/v1/ready` müssen
   nach der Prozessabnahme erfolgreich sein.
