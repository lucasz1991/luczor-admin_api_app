# Redis auf Plesk: private und passwortgeschützte Bereitstellung

Luczor verwendet auf dem Plesk-Host einen ausschließlich an `127.0.0.1`
gebundenen Redis. Die Compose-Konfiguration speichert AOF-Daten dauerhaft unter
`/var/lib/luczor/redis`, startet Redis als unprivilegierten Benutzer und liest das
Passwort aus einem Docker-Secret. Das Redis-Image ist zusätzlich über seinen
geprüften Manifest-Digest unveränderlich gepinnt. Redis selbst bleibt
ausschließlich im internen Compose-Netz. Ein geheimnisfreier nginx-TCP-Gateway veröffentlicht nur den festen
Upstream auf dem Host-Loopback. Der Passwortwert wird nicht als
`redis-server`-Prozessargument übergeben.

## Voraussetzungen und Kernel-Härtung

Redis benötigt auf Linux für zuverlässige Hintergrundspeicherungen
`vm.overcommit_memory=1`. Diese Einstellung gehört zum Host-Kernel und kann nicht
verlässlich im Container gesetzt werden. Als `root` auf dem Plesk-Host:

```bash
printf '%s\n' 'vm.overcommit_memory = 1' > /etc/sysctl.d/99-luczor-redis.conf
chmod 0644 /etc/sysctl.d/99-luczor-redis.conf
sysctl -p /etc/sysctl.d/99-luczor-redis.conf
test "$(sysctl -n vm.overcommit_memory)" = 1
```

Die Datei macht die Einstellung rebootfest. Der vollständige
`luczor:deployment-check --production` prüft den aktiven Wert zusätzlich über
`/proc/sys/vm/overcommit_memory` und schlägt außerhalb eines passenden
Linux-Hosts bewusst fehl. `--configuration-only` führt keinen Kernelzugriff aus.

## Secret vorbereiten

Vom Verzeichnis `admin_api_app` aus erzeugt der vorhandene Initialisierer fehlende
Secret-Dateien ohne Ausgabe ihrer Werte. Der Produktionspfad liegt außerhalb des
Git-Deployments:

```bash
export LUCZOR_DOCKER_SECRETS_DIR=/var/lib/luczor/secrets
install -d -m 0700 -o root -g root "$LUCZOR_DOCKER_SECRETS_DIR"
sh ./docker/init-secrets.sh
test -s "$LUCZOR_DOCKER_SECRETS_DIR/redis_password"
test "$(wc -l < "$LUCZOR_DOCKER_SECRETS_DIR/redis_password")" -le 1
chmod 0600 "$LUCZOR_DOCKER_SECRETS_DIR/redis_password"
```

Das erzeugte Passwort besteht aus mindestens 32 Base64-Zeichen. Es darf nicht in
die Compose-Datei, `.env`, Shell-History, Logs oder `.lmzdev` kopiert werden.

Laravel läuft bei Plesk außerhalb des Containers. Deshalb wird dasselbe Secret
in einen vom PHP-Prozess lesbaren, geschützten Storage-Pfad installiert, ohne es
auf stdout auszugeben:

```bash
app_root=/var/www/vhosts/follow-flow.de/luczor.follow-flow.de
key_dir="$app_root/storage/app/keys"
key_owner="$(stat -c '%U' "$key_dir")"
key_group="$(stat -c '%G' "$key_dir")"
install -m 0600 -o "$key_owner" -g "$key_group" \
  "$LUCZOR_DOCKER_SECRETS_DIR/redis_password" \
  "$key_dir/redis_password"
```

In der Laravel-`.env` wird nur der absolute Dateipfad hinterlegt:

```dotenv
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_URL=
REDIS_PASSWORD=
REDIS_PASSWORD_FILE=/var/www/vhosts/follow-flow.de/luczor.follow-flow.de/storage/app/keys/redis_password
LUCZOR_DOCKER_SECRETS_DIR=/var/lib/luczor/secrets
```

`REDIS_PASSWORD_FILE` und `REDIS_URL` sind absichtlich nicht kombinierbar. Ein
relativer, fehlender, zu kurzer oder mehrzeiliger Secret-Inhalt bricht den
Anwendungsstart fail-closed ab. Beim Config-Cache bleibt nur der Pfad dauerhaft
gespeichert; `AppServiceProvider` lädt den Wert danach in die laufende
Konfiguration für Cache, Queue, Horizon und Reverb-Skalierung.

## Sicherer Wechsel vom bestehenden Plesk-Container

Vor dem Wechsel Wartungsmodus aktivieren, Horizon/Worker stoppen und das
Redis-Verzeichnis sichern. Den bereits laufenden Container erst stoppen, wenn
Backup, Secret-Dateien und Host-Sysctl geprüft sind. Niemals zwei Redis-Prozesse
gleichzeitig an Port 6379 starten.

Die Plesk-Compose-Datei verwendet standardmäßig das bestehende persistente
Verzeichnis `/var/lib/luczor/redis`. Ein abweichender absoluter Pfad wird nur
bewusst über `LUCZOR_REDIS_DATA_DIR` gesetzt.

```bash
export LUCZOR_DOCKER_SECRETS_DIR=/var/lib/luczor/secrets
docker compose -f docker-compose.plesk-memory.yml config --quiet
docker compose -f docker-compose.plesk-memory.yml --profile redis-cutover \
  up -d --wait redis redis-loopback
docker compose -f docker-compose.plesk-memory.yml --profile redis-cutover \
  ps redis redis-loopback
```

Falls noch der separat in Plesk erstellte Container `luczor-redis-auth` Port 6379
belegt, diesen kontrolliert stoppen und den Compose-Dienst erst danach starten.
Das Host-Verzeichnis `/var/lib/luczor/redis` dabei nicht löschen.

## Abnahme ohne Secret-Ausgabe

Zuerst muss ein anonymer Ping mit `NOAUTH` abgewiesen werden; danach muss der
authentisierte Ping `PONG` liefern:

```bash
unauthenticated_reply="$(redis-cli -h 127.0.0.1 -p 6379 ping 2>&1 || true)"
test "$unauthenticated_reply" = 'NOAUTH Authentication required.' \
  || test "$unauthenticated_reply" = '(error) NOAUTH Authentication required.'

REDISCLI_AUTH="$(cat "$LUCZOR_DOCKER_SECRETS_DIR/redis_password")" \
  redis-cli -h 127.0.0.1 -p 6379 ping | grep -qx PONG

ss -ltn | grep -Eq '127\.0\.0\.1:6379[[:space:]]'
! ss -ltn | grep -Eq '(0\.0\.0\.0|\[::\]):6379[[:space:]]'
```

Anschließend Laravel und die langlebigen Prozesse kontrolliert neu laden:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan cache:clear
php artisan horizon:terminate
php artisan luczor:deployment-check --production --configuration-only
php artisan luczor:deployment-check --production
```

Der zweite Check verlangt zusätzlich einen echten Redis-Ping, den aktiven
Kernelwert, Horizon, Scheduler, Migrationen und den Reverb-Listener. Erst danach
darf Traffic wieder freigegeben werden.

## Rotation und Wiederherstellung

Eine Passwortrotation erfolgt im Wartungsmodus: neues zufälliges Secret in eine
temporäre Datei schreiben, atomar sowohl die Docker-Secret-Datei als auch die
Laravel-Storage-Kopie ersetzen, den Redis-Dienst neu erzeugen, Laravel-Konfiguration
und Horizon neu laden und die vollständige Abnahme wiederholen. Das alte Secret
bleibt bis zur erfolgreichen Abnahme in einem geschützten Rollback-Backup.

Redis-Daten werden aus dem gesicherten Host-Verzeichnis wiederhergestellt. Das
Passwort ist kein Bestandteil der AOF-/RDB-Nutzdaten; Secret und Datenbackup
werden deshalb getrennt gesichert und rotiert.
