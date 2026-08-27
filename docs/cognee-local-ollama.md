# Lokales Cognee mit Ollama und FastEmbed

Der Stack in `docker-compose.plesk-memory.yml` verarbeitet LLM- und Embedding-Anfragen lokal. Cognee nutzt `llama3.2:3b` ueber Ollama und das deutsch- sowie mehrsprachig geeignete `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` ueber FastEmbed. Es werden keine Zugangsdaten fuer OpenAI oder andere externe Modell-APIs benoetigt.

## Sicherheits- und Netzwerkmodell

- Cognee und Ollama sind ausschliesslich an interne Docker-Netze angeschlossen.
- Ollama setzt `OLLAMA_NO_CLOUD=1` und besitzt keinen Host-Port.
- Ein separater, geheimnisfreier nginx-Gateway stellt Cognee nur auf
  `127.0.0.1:${COGNEE_HOST_PORT:-8010}` bereit. Das ist erforderlich, weil Docker
  Host-Ports fuer Container verwirft, die ausschliesslich an `internal`-Netzen haengen.
  Der Gateway hat als einziges festes Upstream-Ziel `http://cognee:8000`; Cognee selbst
  erhaelt dadurch weder einen Host-Port noch ein ausgehendes Netz.
- Das FastEmbed-Modell wird bereits beim Bau des Cognee-Images geladen. Im Produktivbetrieb erzwingen die Hugging-Face-Offline-Schalter den lokalen Cache.
- Der opt-in Dienst `ollama-model-bootstrap` hat fuer den einmaligen Modelldownload ein
  ausgehendes Netz. Er gehoert zum deaktivierten Compose-Profil `model-bootstrap` und
  beendet sich nach dem Download. Der nginx-Gateway braucht technisch eine regulaere
  Bridge fuer die Loopback-Veroeffentlichung, besitzt aber keine Secrets und kann nur an
  den fest konfigurierten internen Cognee-Upstream weiterleiten.
- Cognee benoetigt fuer seinen OpenAI-kompatiblen Ollama-Client formal einen nicht leeren API-Key-Wert. Der Entry-Point leitet diesen Kompatibilitaetswert aus dem lokalen Providernamen ab; es ist kein gespeicherter oder hardcodierter Zugangsschluessel und Ollama wertet ihn nicht zur Authentifizierung aus.

Die Datenbank-, Cognee-Service- und Laravel-Geheimnisse bleiben Docker-Secrets. Sie duerfen nicht ins Repository oder in Compose-Umgebungsvariablen geschrieben werden.

## Erstinstallation

Zuerst die normalen Docker-Secrets wie in `docs/cognee-deployment.md` beschrieben erzeugen. Die beiden Provider-Dateien `cognee_llm_api_key` und `cognee_embedding_api_key` sind fuer diesen lokalen Plesk-Stack nicht erforderlich.

Das Ollama-Modell wird bewusst in einem separaten, nachvollziehbaren Schritt geladen:

```bash
docker compose -f docker-compose.plesk-memory.yml --profile model-bootstrap run --rm --no-deps ollama-model-bootstrap
```

Auf einem Plesk-Host mit bereits uebernommenem `luczor-redis-auth` wird Redis nicht erneut
aus diesem Compose-Projekt gestartet. Erst bestaetigen, dass dessen Container-ID und
Healthcheck unveraendert sind. Danach die uebrigen Dienste einzeln und ohne implizites
Starten von Abhaengigkeiten hochfahren:

```bash
docker compose -f docker-compose.plesk-memory.yml up -d --no-deps postgres
docker compose -f docker-compose.plesk-memory.yml run --rm --no-deps cognee-db-init
docker compose -f docker-compose.plesk-memory.yml up -d --no-deps ollama
docker compose -f docker-compose.plesk-memory.yml up -d --no-deps cognee
docker compose -f docker-compose.plesk-memory.yml up -d --no-deps cognee-loopback
```

Ohne erfolgreich geladenes Modell bleibt der Ollama-Healthcheck absichtlich fehlerhaft und Cognee startet nicht.

## Ressourcen

Die Vorgaben sind CPU-orientiert und laden nur ein Modell mit einer parallelen Anfrage:

- Ollama: maximal 4 GiB RAM und 2 CPUs, Kontext 8192, quantisierter KV-Cache.
- Cognee: maximal 4 GiB RAM und 2 CPUs.
- FastEmbed: kleine Batches von standardmaessig 4 Elementen.

Die Grenzwerte koennen ueber `LUCZOR_OLLAMA_MEMORY_LIMIT`, `LUCZOR_OLLAMA_CPU_LIMIT`, `LUCZOR_COGNEE_MEMORY_LIMIT` und `LUCZOR_COGNEE_CPU_LIMIT` abgesenkt werden. Zu enge Grenzen fuehren zu OOM-Abbruechen oder deutlich hoeherer Latenz; fuer den gesamten Stack sollten in der Praxis mindestens 8 GiB freier RAM oder ausreichend zusaetzlicher Swap eingeplant werden. Auf Linux muss persistenter Swap vor dem ersten Modelllauf aktiv und nach einem Neustart weiterhin nachweisbar sein.

Ein anderes Ollama-Modell wird mit `COGNEE_OLLAMA_LLM_MODEL` gewaehlt und anschliessend erneut ueber den Bootstrap geladen. Das Embedding-Modell und seine Dimension von 384 bilden dagegen einen Datenvertrag: Eine Aenderung erfordert ein neues Image sowie eine kontrollierte Neuindizierung der Vektordaten.

## Pruefung vor dem Start

```bash
docker compose -f docker-compose.plesk-memory.yml config --quiet
docker compose -f docker-compose.plesk-memory.yml --profile model-bootstrap config --quiet
php artisan test --filter='Cognee(LocalProvider|Plesk)DeploymentTest'
git ls-files '*.sh' | while read -r file; do
  test "$(tr -cd '\r' < "$file" | wc -c)" -eq 0 || exit 1
  sh -n "$file"
done
```

Nach dem Deployment muessen Add, Cognify, Search und Forget mit einem synthetischen Test-Dataset als Live-Smoke-Test ausgefuehrt werden. Dabei duerfen weder Repository-Code noch echte Benutzererinnerungen verwendet werden.

## Technische Grundlagen

- [Cognee: lokale LLM- und FastEmbed-Konfiguration](https://docs.cognee.ai/getting-started/llm-quickstart-skill)
- [Cognee: Embedding-Provider und Dimensionen](https://docs.cognee.ai/setup-configuration/embedding-providers)
- [Cognee: Ollama-Endpunkt mit `/v1`](https://docs.cognee.ai/setup-configuration/llm-providers)
- [Ollama: lokale Docker-Datenablage](https://docs.ollama.com/docker)
- [Ollama: Cloud-Abschaltung und Ressourcensteuerung](https://docs.ollama.com/faq)
- [FastEmbed: unterstuetzte lokale Modelle](https://qdrant.github.io/fastembed/examples/Supported_Models/)
- [Docker/Moby: Port-Publishing bei ausschliesslich internen Netzen](https://github.com/moby/moby/discussions/53256)
