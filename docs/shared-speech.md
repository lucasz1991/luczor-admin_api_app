# Gemeinsame Server-Sprachausgabe

Luczor verwendet für TTS den bereits vorhandenen privaten LMZ Speech Service
von FollowFlow/RailTime auf demselben Plesk-Host. Die Desktop-App sendet den
Vorlesetext über ihre authentifizierte Luczor-API. Die lokale Spracheingabe
(Whisper/STT) bleibt unabhängig davon. Für TTS wird eine Serververbindung benötigt.

## Vertrag

`POST /api/v1/voice/tts` verlangt einen aktiven Luczor-API-Key mit `proxy.use`
(Bearer oder `X-Api-Key`) und JSON:

```json
{"text":"Dieser Text wird vorgelesen.","language":"de","speed":1.0}
```

`text` enthält 1–4000 Zeichen; die gesamte JSON-Anfrage darf höchstens 32 KiB
belegen. `language` ist optional und akzeptiert nur `de`/`de-DE`; `speed` ist
optional, Standard `1.0`, Bereich `0.5`–`2.0`. Unbekannte Felder werden abgewiesen.
Erfolg ist binäres `audio/wav` bis 16 MiB mit `Cache-Control: private, no-store`.
Es gibt keine Base64-Hülle und keine Audio-URL.

Laravel sendet ausschließlich `{"text":...,"speed":...}` an
`http://127.0.0.1:8092/v1/speech`. Die deutsche Stimme bestimmt die vorhandene
Piper-Konfiguration des zentralen Dienstes. Header sind ein ausschließlich
serverseitiges Bearer-Token, `X-Client-ID: luczor` und eine neue UUID als
`X-Request-ID`. Luczors Desktop-Key wird niemals an den Dienst weitergereicht.

Der Adapter startet keine eigenen Engineprozesse. Native PHP-cURL begrenzt
Connect auf 2 Sekunden und den gesamten Transfer einschließlich Antwortbody
auf 150 Sekunden. Body-Limit und Content-Length werden während des Empfangs
geprüft. Redirects, HTTP-Proxies aus Umgebungsvariablen und automatische
Wiederholungen sind deaktiviert. Ein falscher Endpunkt, fehlender Schlüssel
oder ausgefallener Dienst erzeugt einen sichtbaren Fehler.

30 TTS-Anfragen pro Minute gelten je authentifiziertem Benutzer über alle
seine Geräteschlüssel hinweg. Statusabrufe haben ein separates Limit von 12
pro Minute. Der vorhandene allgemeine API-Limiter gilt zusätzlich.

## Server aktivieren

### Bestätigter Live-Stand am 6. September 2026

Die Anbindung ist auf `luczor.follow-flow.de` aktiviert. Luczor verwendet
`lmz-speech-service` unter Supervisor auf dem gemeinsamen Plesk-Host, nicht
einen neuen Docker-Container. Die vorhandene deutsche Piper-Stimme ist
`de_DE-thorsten-medium`; der Dienst bleibt auf `127.0.0.1:8092` beschränkt.
Alle drei Dienstidentitäten (`luczor`, `followflow`, `railtime`) wurden nach
dem gezielten Neustart authentifiziert als Piper-`ready` bestätigt.

Ein realer HTTPS-/PHP-FPM-Test lieferte HTTP 401 ohne Key und HTTP 200 mit
einem kurzlebigen `proxy.use`-Testkey. Die Antwort enthielt 180.780 Bytes
nicht-stille PCM-WAV-Daten (22.050 Hz, mono, 4,098 Sekunden) nach 1.037 ms.
TLS, Tauri-CORS und `no-store` bestanden; der Testkey wurde wieder gelöscht.
Das ist eine einzelne Funktionsmessung, kein Lastbenchmark und keine hörbare
Qualitätsabnahme. Lokale STT wurde nicht verändert.

Der dedizierte Tokenpfad unten ist auf diesem Host bestätigt. Die gemeinsame
FollowFlow-Subscription verwendet denselben Unix-Benutzer für FollowFlow und
Luczor; getrennte Clienttokens sind deshalb keine Dateisystem-Isolation.
Backup und Deploymentnachweis: `/var/backups/luczor-shared-tts-20260906T014113Z`
(nur serverseitig für root). Es wurden neun PHP-Runtime-Dateien, ausschließlich
die Luczor-`SHARED_SPEECH_*`-Werte und der zusätzliche Serviceclient installiert.
Keine Migration, kein Modellupdate und keine Änderung an den anderen Clients.

### Einrichtung auf weiteren Umgebungen

Die Integration ist standardmäßig ausgeschaltet, bis die eigene Luczor-
Dienstidentität eingerichtet ist. Das bestehende Service-Release, die Modelle
und die Clients von FollowFlow/RailTime bleiben bestehen.

1. Auf dem bestätigten Plesk-Host den vorhandenen Supervisor-Dienst,
   dessen tatsächliche Config-Datei und den PHP-FPM-Benutzer von Luczor
   identifizieren. Der Dienst muss weiterhin ausschließlich auf
   `127.0.0.1:8092` und als genau ein Supervisor-Prozess laufen.
2. Einen eigenen starken Zufallsschlüssel für Client `luczor` anlegen und
   ausschließlich seinen SHA-256-Hash in der bestehenden Service-Config unter
   `clients.luczor.token_sha256` ergänzen. Alle vorhandenen Clients/Engine-
   Einstellungen erhalten. Kein Token einer anderen Anwendung wiederverwenden.
3. Das Klartexttoken in einer dedizierten Datei außerhalb von Checkout und
   `public/` hinterlegen, nur vom Luczor-PHP-FPM-Benutzer lesbar (z. B. Modus
   `0600`). Bei der bestätigten FollowFlow-Subscription ist der eigene Pfad
   `/var/www/vhosts/follow-flow.de/.lmz-secrets/luczor-speech-service.token`.
   Eigentümer und reale Subscription vorher prüfen. Die vorhandene
   `speech-service.token` von FollowFlow darf nicht überschrieben werden.
   Ein gemeinsamer Unix-Benutzer bedeutet außerdem keine Dateisystem-Isolation
   zwischen den Anwendungen; die getrennten Tokens trennen die Service-Identität.
4. In der **Luczor**-Serverumgebung einstellen:

```dotenv
SHARED_SPEECH_ENABLED=true
SHARED_SPEECH_URL=http://127.0.0.1:8092
SHARED_SPEECH_CLIENT_ID=luczor
SHARED_SPEECH_TOKEN_FILE=/var/www/vhosts/follow-flow.de/.lmz-secrets/luczor-speech-service.token
SHARED_SPEECH_TOKEN=
```

Der Pfad gilt für den oben bestätigten Host, nicht pauschal für andere Installationen. Eine gesetzte
`SHARED_SPEECH_TOKEN_FILE` hat Vorrang; bei fehlender/unlesbarer Datei gibt es
keinen Rückfall auf den Umgebungswert. Alternativ ist ausschließlich der
serverseitige Umgebungswert `SHARED_SPEECH_TOKEN` möglich. Keine dieser
Einstellungen gehört in Vite, den Desktop-Build oder einen API-Response.
PHP-cURL muss in der tatsächlichen PHP-FPM-Version aktiviert sein.

5. Die bestehende Service-Config mit dem installierten Service-Werkzeug prüfen
   und den genau identifizierten Speech-Service kontrolliert neu laden bzw.
   neu starten. Einen allgemeinen Provisionierungshelfer nicht blind erneut
   ausführen: dessen Token-Ausgabepfad kann vorhandene Tokens überschreiben.
6. Mit der PHP-Version und dem Arbeitsverzeichnis der Luczor-Anwendung deren
   vorhandene Caches gezielt erneuern und Status sowie synthetischen Audiotest
   ausführen. Im bestätigten Live-System waren Config- und Routecache nicht
   aktiv; sie wurden nur geleert, nicht neu eingeführt:

```bash
php artisan config:clear
php artisan luczor:speech-service-status
php artisan luczor:speech-service-status --smoke
```

Der Status verwendet den authentifizierten `/v1/status` und verlangt
`engines.piper=ready`. Ein allein wegen STT gemeldetes `degraded` verhindert TTS
nicht. `--smoke` synthetisiert einen festen deutschen Testsatz, prüft WAV und
verwirft das Audio ohne Datei. Ausgabe enthält Status und Bytezahl, keine
Tokens oder Vorlesetexte. Beide Befehle liefern Exit 1, solange die jeweilige
Abnahme fehlschlägt. `/healthz` allein belegt keine funktionierende Sprachausgabe.

7. PHP-FPM-/Plesk-/Reverse-Proxy-Requestlimits mit dem 150-Sekunden-Servicebudget
   abstimmen (z. B. mindestens 165 Sekunden für den äußeren Request). Danach
   in der verbundenen Desktop-App einen deutschen Satz vorlesen, abbrechen
   und erneut starten. Die Smoke-Byteprüfung belegt keine hörbare Qualität.

Eine Containerinstallation benötigt eine ausdrücklich geplante Verbindung zum
Hostdienst: `127.0.0.1` in einem Container ist nicht der Plesk-Host. Die vorliegende
Integration erwartet PHP-FPM auf demselben Host und öffnet keine externe Service-URL.

## Diagnose und Datenschutz

`GET /api/v1/voice/tts/status` benötigt ebenfalls `proxy.use` und liefert nur
`configured`, `ready`, `status`, `language`, `max_text_chars`, `max_audio_bytes`.
Statusantworten werden nicht gecacht. API-Fehler enthalten sichere feste
`error.code`/`error.message`-Werte; interne Antworttexte, Token und Pfade werden
nicht weitergegeben. Typische Codes sind `tts_not_configured`,
`tts_service_auth_failed`, `tts_unavailable`, `tts_timeout`, `tts_invalid_audio`.
Ein abgelaufener Desktop-Key bleibt ein Luczor-401, ein falscher interner
Service-Key ein 503 `tts_service_auth_failed`.

Laravel speichert und protokolliert weder Text noch Audio, nutzt keine
Audio-Cache-/Datenbank-/Tempdatei und verwirft den Speicher mit dem Request.
Der vorhandene zentrale Dienst kann für seine Piper-CLI kurzlebige Dateien
in seinem eigenen geschützten Temp-Verzeichnis erzeugen und räumt diese auf.
Die Betreiber müssen ergänzende Proxy-/APM-/Debug-Body-Aufzeichnungen für
diese Route ausgeschaltet lassen. Der Text verlässt das Desktopgerät für
die vom Benutzer gewünschte serverseitige Sprachausgabe.

## Verifikation im Repository

```bash
php artisan test --compact --filter=SharedSpeech
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

API-Tests prüfen Authentifizierung, Scope, Kontolimits, Schema, Secret-/URL-
Grenzen, Fehler und Status/Smoke. Ein echter synthetischer Loopback-Test
prüft den cURL-Wire-Vertrag, Redirectverbot, Größenlimits mit/ohne
Content-Length und die Deadline nach bereits empfangenen Response-Headern.
Die produktive Verbindung wurde wie oben beschrieben separat abgenommen.
Hörbare Qualität, Mikrofon-Echo/Barge-in auf dem Zielgerät und Lastverhalten
mehrerer gleichzeitiger Benutzer bleiben separate Prüfungen. Bestehende
Desktop-Installer benötigen einen neuen Build, um den geänderten Vue-Code
zu enthalten; durch die Backendaktivierung wird kein installierter Client ersetzt.
