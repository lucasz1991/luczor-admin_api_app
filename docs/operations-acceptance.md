# Luczor Betriebs- und Release-Abnahme

Der Acceptance-Runner verbindet die vorhandenen Backend- und Desktop-Gates, ohne eine
Produktionsdatenbank zu öffnen, Netzwerkdienste zu starten, Migrationen auszuführen,
Accounts zu verändern oder Zertifikate zu erzeugen. Ohne ausdrücklich erfasste externe
Belege bleibt die Gesamtfreigabe absichtlich rot.

Die Prüflogik orientiert sich an den offiziellen Hinweisen für
[Laravel-Deployments](https://laravel.com/framework/docs/12.x/deployment),
[Horizon](https://laravel.com/framework/docs/12.x/horizon),
[Scheduler](https://laravel.com/framework/docs/12.x/scheduling),
[Reverb](https://laravel.com/framework/docs/12.x/reverb) sowie an den Tauri-2-Anleitungen
für [Windows-Signing](https://v2.tauri.app/distribute/sign/windows/),
[macOS-Signing](https://v2.tauri.app/distribute/sign/macos/) und den
[Updater](https://v2.tauri.app/plugin/updater/).

## 1. Netzlose lokale Vorprüfung

Vom Laravel-Unterrepository aus:

```text
php artisan luczor:operations-acceptance --workspace-root=.. --local-only
```

Geprüft werden ausschließlich lokale, nicht geheime Voraussetzungen:

- migrationssicherer Deployment-Check und vorhandene Migrationen,
- Horizon-/Reverb-Pakete, Scheduler-Heartbeat und Runtime-Probes,
- persistente Notification-, private Channel-, Gerätejob- und Catch-up-Pfade,
- Tauri-Hauptfenster, Node-Vorgabe, OS-Keychain, Audioein- und -ausgabe,
- Notification-Permissions sowie Approval-Grenzen für Datei- und Computerwerkzeuge,
- die vorhandene fail-closed Release-Pipeline für Updater und Plattform-Signing.

Der Befehl liest nur begrenzte lokale Dateien und führt den vorhandenen
`app/scripts/release-readiness.cjs --mode local-test`-Preflight aus. Unter Windows läuft
dieser über `app/scripts/with-pinned-node.ps1`; der Wrapper verwendet den installierten
`.nvmrc`-Pin nur im Kindprozess und führt bewusst kein globales `nvm use` aus. Fehlt diese
gepinnten Node-Version, blockiert der lokale Lauf. Der Runner macht keine HTTP-,
Datenbank-, Redis- oder Socket-Anfrage. `--local-only` bestätigt deshalb niemals
Produktionsbereitschaft; es bestätigt nur, dass die lokalen Voraussetzungen vorhanden
sind.

## 2. Externe Evidence-Datei

`docs/operations-acceptance-evidence.example.json` in einen geschützten Abnahmebereich
kopieren und erst nach realen Prüfungen ausfüllen. Die Datei ist eine Attestation, kein
Secret-Store. Sie darf keine Passwörter, Tokens, privaten Schlüssel, Zertifikatsdateien
oder Recovery-Codes enthalten. Unbekannte JSON-Felder werden abgelehnt; Werte werden im
Runner-Output nie wiedergegeben.

Gesamtprüfung:

```text
php artisan luczor:operations-acceptance --workspace-root=.. --evidence=C:\geschuetzt\luczor-acceptance.json
```

Maschinenlesbare Ausgabe ohne Evidence-Werte:

```text
php artisan luczor:operations-acceptance --workspace-root=.. --evidence=C:\geschuetzt\luczor-acceptance.json --json
```

Der normale Lauf liefert einen Fehlerstatus, solange ein lokaler Check, das Schema oder
ein externer Beleg fehlt. Die Evidence bindet die Abnahme an Backend-/Desktop-Revision,
Release-Version und SHA-256 des tatsächlich getesteten Desktop-Artefakts.
Maschinenlesbar werden fehlende externe Belege ausdrücklich als `blocked` ausgegeben;
`--local-only` überspringt oder vergoldet diesen Zustand nicht.

Die Revisionen sind keine frei formulierbaren Referenzen: Der Runner liest die beiden
lokalen Git-`HEAD`s und verlangt exakte Übereinstimmung. `desktop.artifactPath` muss ein
workspace-relativer Pfad ohne `..` sein, dessen aufgelöste Datei im Workspace bleibt; der
Runner berechnet ihren SHA-256 selbst und vergleicht ihn mit `artifactSha256`. Eine
Attestation ist höchstens 72 Stunden alt und höchstens fünf Minuten in der Zukunft gültig.
Damit muss die Prüfung bei einem späteren Release oder Artefakt erneut erfolgen.

## 3. Produktive Migration

Vor jedem mutierenden Migrationsbefehl müssen folgende Werte außerhalb der Evidence-Datei
bereitstehen und vom Betreiber bestätigt werden:

- `APP_ENV`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` und
  `DB_PASSWORD` beziehungsweise `DATABASE_URL`,
- eindeutige Zielumgebung und freigegebene Backend-Revision,
- unveränderliche Backup-Referenz plus nachgewiesener Restore-Test,
- konkrete Rollback-Anweisung und verantwortliche Person.

Erst danach führt der Betreiber die Migration in der Zielumgebung aus. Anschließend
müssen `php artisan migrate:status` und
`php artisan luczor:deployment-check --production` erfolgreich sein. Der Acceptance-
Runner führt keinen dieser mutierenden Schritte selbst aus.

## 4. Redis, Horizon, Scheduler und Reverb

Die Zielumgebung benötigt reale, nicht erfundene Werte für:

- `CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `REDIS_HOST`, `REDIS_PORT`, optional
  `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_URL` und TLS-Vorgaben,
- `HORIZON_QUEUES`, Supervisor-/Plesk-Prozessdefinition, Restart- und Monitoring-Regel,
- einen minütlichen Aufruf von `php artisan schedule:run` und den frischen Luczor-Heartbeat,
- `BROADCAST_DRIVER=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`,
  `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT`, `REVERB_HOST`, `REVERB_PORT`,
  `REVERB_SCHEME`, `REVERB_ALLOWED_ORIGINS` sowie die öffentlichen
  `LUCZOR_REVERB_PUBLIC_*`-Werte.

Abzunehmen sind Redis-Ping, laufender Horizon-Master, Scheduler-Heartbeat, interner
Reverb-Socket, Reverse-Proxy-WebSocket, privater Device-Channel und der persistente
REST-Catch-up nach einer Offline-Phase. Referenzen auf Supervisor, Cron, Monitoring und
Endpoints gehören in die Evidence; deren Credentials nicht.

## 5. Gepackte Desktop-App

Die Abnahme erfolgt mit dem exakt gehashten Release-Artefakt in einer realen GUI-Sitzung:

1. WebView startet und die Hauptnavigation funktioniert.
2. Ein Testwert wird über die OS-Keychain geschrieben, nach Neustart gelesen und wieder
   kontrolliert entfernt; Klartext-Persistenz wird ausgeschlossen.
3. Mikrofoneingabe, STT, Audioausgabe, Unterbrechung und Hotkey werden mit dem vorgesehenen
   Gerät geprüft.
4. Notification-Permission, Anzeige und Klickaktion werden geprüft.
5. Reverb wird kurz getrennt; nach Wiederverbindung holt die App fehlende persistente
   Notifications per Cursor nach.
6. Datei- und Computerwerkzeuge werden nur in einem temporären Testprojekt geprüft. Reads,
   Writes und Steueraktionen müssen die Approval-Grenze zeigen und dürfen das gebundene
   Projekt nicht verlassen.

Erforderliche Evidence-Werte sind Artefakt-Referenz, workspace-relativer Artefaktpfad,
der vom Runner nachgerechnete SHA-256, Betriebssystem, WebView-Version und die einzelnen
booleschen Prüfergebnisse. Reale persönliche Projekte oder deren Inhalte gehören nicht in
die Evidence.

## 6. Signing, Updater und Restore

Die bestehende Desktop-Workflow-Datei benennt die geheimen CI-Werte. Sie müssen im
geschützten CI-Secret-Store liegen und dürfen niemals in die Evidence kopiert werden:

- `TAURI_SIGNING_PRIVATE_KEY`, `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`,
- `WINDOWS_CERTIFICATE`, `WINDOWS_CERTIFICATE_PASSWORD`,
- `APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_ID`, `APPLE_PASSWORD`,
  `APPLE_SIGNING_IDENTITY`, `APPLE_TEAM_ID`.

Zusätzlich müssen `bundle.createUpdaterArtifacts`, `plugins.updater.endpoints`,
`plugins.updater.pubkey` und die registrierte `tauri-plugin-updater`-Runtime vollständig
sein. Die Evidence enthält nur HTTPS-Updater-URL, Public-Key-Fingerprint,
Zertifikatsidentität, Zielplattformen und Prüfergebnisse. Für Windows ist die
Authenticode-Signatur zu verifizieren; für macOS zusätzlich Signatur und Notarisierung.
Ein Update auf das geprüfte Artefakt und ein dokumentierter Restore/Rollback müssen real
durchlaufen sein.

## 7. Klare Restgrenze

Ein grüner lokaler Lauf belegt Code- und Gate-Voraussetzungen. Erst eine gültige,
revisionsgebundene Evidence-Datei belegt die externe Betriebsabnahme. Weder die Datei noch
der Runner ersetzen Monitoring, Backup-Aufbewahrung, Secret-Rotation oder eine
Freigabeentscheidung des Betreibers.
