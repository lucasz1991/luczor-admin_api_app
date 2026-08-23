# Luczor Sync API

Der Desktop-Client bleibt für den lokalen Zustand zuständig; das Backend archiviert
Projects, Messages, Memories und Summaries benutzer- und gerätegebunden.

## Push

`POST /api/v1/sync/push` benötigt `sync.write` und eine `client_id`. Der komplette Batch
wird vor dem ersten Schreibzugriff validiert und anschließend in einer Transaktion
gespeichert. Wiederholungen derselben Kombination aus Benutzer, Client, Entity-Typ und
externer ID aktualisieren denselben Datensatz.

Grenzen:

- höchstens 5 MiB pro Request,
- höchstens 500 Einträge pro Bucket,
- höchstens 256 KiB und 16 Ebenen pro Eintrag,
- IDs/Projekt-IDs höchstens 120 Zeichen, Namen höchstens 255 Zeichen,
- Client-Zeitwerte als ISO-Datum oder Unix-Zeit in Sekunden/Millisekunden.

## Pull und Snapshot-Paginierung

`GET /api/v1/sync/pull` benötigt `sync.read`. `since` bleibt als ISO-Zeitwert für ältere
Clients erhalten. `limit` gilt pro Bucket und liegt zwischen 1 und 500.

Jeder Bucket wird stabil nach `(updated_at, id)` sortiert. Die erste Seite friert für
jeden Bucket eine obere Keyset-Grenze ein. Parallel neu angelegte Datensätze geraten
dadurch nicht mitten in die laufende Seitensequenz.

Die Antwort enthält neben `data`:

```json
{
  "cursor": "2026-08-22T12:00:00.000000Z",
  "has_more": true,
  "continuation": {
    "projects": { "has_more": true, "cursor": "opaque" },
    "messages": { "has_more": false, "cursor": "opaque" },
    "memories": { "has_more": false, "cursor": "opaque" },
    "summaries": { "has_more": false, "cursor": "opaque" }
  }
}
```

Für jede Folgeseite sendet der Client alle vier unverändert übernommenen Tokens als
`cursors[projects]`, `cursors[messages]`, `cursors[memories]` und `cursors[summaries]`.
Die Tokens sind verschlüsselt, bucketgebunden und dürfen nicht interpretiert werden.
Erst wenn das globale `has_more` den Wert `false` hat, darf der Zeitwert aus `cursor`
als nächstes Legacy-`since` gespeichert werden.
