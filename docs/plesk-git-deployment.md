# Plesk-Git-Deployment ohne Runtime-Dateien im Checkout

Der Plesk-Deployment-Benutzer muss Git-verwaltete Quelldateien ersetzen können. Docker-
Secrets, `.env`, `vendor`, generierte Laravel-Caches und persistente Daten gehören dagegen
nicht in diesen Besitz- oder Deploymentbereich.

## Einmalige Bereinigung

1. `LUCZOR_DOCKER_SECRETS_DIR=/var/lib/luczor/secrets` in der produktiven `.env` setzen.
2. Das Verzeichnis als `root:root` mit Modus `0700` und seine Dateien mit `0600` anlegen.
3. Die vorhandenen Secret-Dateien wertgleich kopieren und ohne Inhaltsausgabe per
   `cmp -s` prüfen.
4. Den neuen Commit in das Plesk-Bare-Repository abrufen.
5. Nur die vom Commit verwalteten Pfade reparieren:

   ```bash
   bash ./docker/repair-plesk-git-permissions.sh \
     /var/www/vhosts/follow-flow.de/luczor.follow-flow.de \
     /var/www/vhosts/follow-flow.de/git/laravel_5c391f \
     follow-flow.de_0bojambz9vsp \
     https://github.com/lucasz1991/luczor-admin_api_app.git
   ```

Das Skript bricht ab, sobald geschützte Runtime-Pfade noch getrackt sind, ein Pfad aus dem
Ziel ausbricht, ein unerwarteter Symlink vorkommt oder Repository/Origin nicht exakt passen.
Vor jeder Metadatenänderung schreibt es ein ACL-Rollback unter `/var/backups/luczor`.
Ein pauschales `chown -R` ist ausdrücklich nicht zulässig.

## Deployment und Abnahme

Nach dem Rechte-Fix wird ausschließlich über die Plesk-Git-Integration bereitgestellt.
Danach müssen Compose-Konfiguration, externe Secret-Mounts, Container-Healthchecks,
Laravel-Readiness, Cognee sowie ein weiterer Plesk-Deploy ohne manuelle Rechtekorrektur
geprüft werden. Docker-Kommandos als `root` dürfen nur unter `/var/lib/luczor` und in
Docker-Volumes schreiben, niemals wieder in den Git-Checkout.

Das ACL-Backup stellt bei Bedarf die vorherigen Metadaten wieder her:

```bash
setfacl --restore=/var/backups/luczor/permissions-before-git-repair-YYYYMMDDTHHMMSSZ.acl
```
