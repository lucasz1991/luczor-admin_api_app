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

BROADCAST_DRIVER=reverb
QUEUE_CONNECTION=redis
LUCZOR_NOTIFICATION_QUEUE=notifications

REVERB_APP_ID=luczor
REVERB_APP_KEY=<oeffentlicher-zufaelliger-key>
REVERB_APP_SECRET=<geheimer-zufaelliger-key>

REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
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

## 2. Dauerhafte Prozesse

Für die Domain müssen zwei dauerhaft laufende Prozesse eingerichtet werden:

```text
php artisan reverb:start --host=127.0.0.1 --port=8080
php artisan queue:work redis --queue=notifications,default --tries=4 --timeout=90
```

In Plesk können diese über Supervisor oder systemd verwaltet werden. Beide Prozesse
sollen automatisch starten und nach einem Fehler neu gestartet werden. Nach einem
Deployment:

```text
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

## 3. WebSocket-Proxy

Plesk/nginx leitet den WebSocket-Pfad der HTTPS-Domain an
`http://127.0.0.1:8080` weiter. Dabei müssen `Upgrade` und `Connection: upgrade`
erhalten bleiben. Der öffentliche Client-Endpunkt ist anschließend
`wss://luczor.follow-flow.de`.

## 4. Abnahmetest

1. Tauri-App installieren und mit einem Device-Key mit `device.connect` anmelden.
2. Unter Einstellungen → Benachrichtigungen Push ausdrücklich aktivieren.
3. Eine Geräteaufgabe abschließen oder einen Root-Workflow in einen Endstatus bringen.
4. Prüfen, dass genau eine native Meldung erscheint.
5. Luczor über das Fenster-X in den Tray schließen und den Test wiederholen.
6. Reverb kurz stoppen, ein Ereignis erzeugen, Reverb starten und Luczor fokussieren.
   Die gespeicherte Meldung muss über den REST-Cursor nachgeholt werden.
7. Die Meldung anklicken. Luczor muss fokussiert, die Meldung als gelesen markiert und
   die Benachrichtigungsansicht geöffnet werden.
