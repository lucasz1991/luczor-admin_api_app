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
- Die von Kuzu/Ladybug benoetigte JSON-Erweiterung wird ebenfalls ausschließlich beim
  Image-Build geladen, fuer amd64 beziehungsweise arm64 per SHA-256 verifiziert und in
  das Image eingebettet. Cognify und Forget laden im internen Runtime-Netz keinen Code nach.
- Der opt-in Dienst `ollama-model-bootstrap` hat fuer den einmaligen Modelldownload ein
  ausgehendes Netz. Er gehoert zum deaktivierten Compose-Profil `model-bootstrap` und
  beendet sich nach dem Download. Der nginx-Gateway braucht technisch eine regulaere
  Bridge fuer die Loopback-Veroeffentlichung und besitzt keine Secrets. Seine nginx-
  Konfiguration leitet ausschliesslich an den fest vorgegebenen internen Cognee-Upstream;
  die Bridge selbst ist keine netzseitige Egress-Sperre.
- Cognee benoetigt fuer seinen OpenAI-kompatiblen Ollama-Client formal einen nicht leeren API-Key-Wert. Der Entry-Point leitet diesen Kompatibilitaetswert aus dem lokalen Providernamen ab; es ist kein gespeicherter oder hardcodierter Zugangsschluessel und Ollama wertet ihn nicht zur Authentifizierung aus.

Die Datenbank-, Cognee-Service- und Laravel-Geheimnisse bleiben Docker-Secrets. Sie duerfen nicht ins Repository oder in Compose-Umgebungsvariablen geschrieben werden.

## Erstinstallation

Zuerst die normalen Docker-Secrets wie in `docs/cognee-deployment.md` beschrieben erzeugen:

```bash
(umask 077; sh ./docker/init-secrets.sh)
```

Die beiden Provider-Dateien `cognee_llm_api_key` und `cognee_embedding_api_key` sind fuer diesen lokalen Plesk-Stack nicht erforderlich.

Das Ollama-Modell wird bewusst in einem separaten, nachvollziehbaren Schritt geladen:

```bash
docker compose -f docker-compose.plesk-memory.yml pull postgres ollama cognee-loopback
docker compose -f docker-compose.plesk-memory.yml build --pull cognee
docker compose -f docker-compose.plesk-memory.yml --profile model-bootstrap run --rm --no-deps ollama-model-bootstrap
```

Auf einem Plesk-Host mit bereits uebernommenem `luczor-redis-auth` wird Redis nicht erneut
aus diesem Compose-Projekt gestartet. Erst bestaetigen, dass dessen Container-ID und
Healthcheck unveraendert sind. Danach die uebrigen Dienste einzeln und ohne implizites
Starten von Abhaengigkeiten hochfahren:

```bash
docker compose -f docker-compose.plesk-memory.yml up -d --wait --no-deps postgres
docker compose -f docker-compose.plesk-memory.yml run --rm --no-deps cognee-db-init
docker compose -f docker-compose.plesk-memory.yml up -d --wait --no-deps ollama
docker compose -f docker-compose.plesk-memory.yml up -d --wait --no-deps cognee
docker compose -f docker-compose.plesk-memory.yml up -d --wait --no-deps cognee-loopback
```

Redis besitzt zusaetzlich das Profil `redis-cutover`. Dadurch startet ein
unqualifiziertes `docker compose up` keinen zweiten Redis. Der explizite Befehl
`docker compose --profile redis-cutover up -d redis` ist ausschliesslich fuer einen
geplanten Erst-Cutover vorgesehen.

Ohne erfolgreich geladenes Modell bleibt der Ollama-Healthcheck absichtlich fehlerhaft und Cognee startet nicht.

## Ressourcen

Die Vorgaben sind CPU-orientiert und laden nur ein Modell mit einer parallelen Anfrage:

- Ollama: maximal 4 GiB RAM und 2 CPUs, Kontext 8192, quantisierter KV-Cache.
- Cognee: maximal 4 GiB RAM und 2 CPUs.
- FastEmbed: kleine Batches von standardmaessig 4 Elementen.

Die Grenzwerte koennen ueber `LUCZOR_OLLAMA_MEMORY_LIMIT`, `LUCZOR_OLLAMA_CPU_LIMIT`, `LUCZOR_COGNEE_MEMORY_LIMIT` und `LUCZOR_COGNEE_CPU_LIMIT` abgesenkt werden. Zu enge Grenzen fuehren zu OOM-Abbruechen oder deutlich hoeherer Latenz. Auf einem 8-GiB-Plesk-Host sind mindestens 8 GiB persistenter Swap als Notreserve sinnvoll; Swap ersetzt jedoch keinen RAM und kann Modelllaeufe deutlich verlangsamen. Vor und nach einem Neustart muessen die Reserven und Kernelwerte nachweisbar sein:

```bash
free -h
swapon --show --bytes
sysctl vm.overcommit_memory
grep -F '/swapfile-luczor none swap sw 0 0' /etc/fstab
```

Ein anderes Ollama-Modell wird mit `COGNEE_OLLAMA_LLM_MODEL` gewaehlt und anschliessend erneut ueber den Bootstrap geladen. Das Embedding-Modell und seine Dimension von 384 bilden dagegen einen Datenvertrag: Eine Aenderung erfordert ein neues Image sowie eine kontrollierte Neuindizierung der Vektordaten.

## Pruefung vor dem Start

```bash
docker compose -f docker-compose.plesk-memory.yml config --quiet
docker compose -f docker-compose.plesk-memory.yml --profile model-bootstrap config --quiet
php artisan test --filter='Cognee(LocalProvider|Plesk)DeploymentTest'
for file in \
  docker/init-secrets.sh \
  docker/provision-cognee.sh \
  docker/redis/entrypoint.sh \
  docker/postgres/entrypoint.sh \
  docker/postgres/configure-cognee-hba.sh \
  docker/postgres/ensure-cognee-db.sh \
  docker/postgres/init/05-configure-cognee-hba.sh \
  services/cognee/entrypoint.sh
do
  test "$(tr -cd '\r' < "$file" | wc -c)" -eq 0 || exit 1
  sh -n "$file"
done
```

Nach dem Deployment muessen Add, Cognify, Search und Forget mit synthetischen Daten als
Live-Smoke-Test ausgefuehrt werden. Der Befehl erzeugt einen kurzlebigen Benutzer und
API-Key nur im Arbeitsspeicher, erzwingt exaktes Provider-Forget und entfernt anschließend
alle Kontozuordnungen. Er benötigt wegen des echten temporären Produktionswrites eine
explizite Freigabe:

```bash
php artisan luczor:memory-production-smoke --force --timeout=1800
```

Dabei werden weder Repository-Code noch echte Benutzererinnerungen verwendet. Ein PASS
wird erst ausgegeben, nachdem die Cognee-Data-ID nicht mehr auffindbar ist.

## Technische Grundlagen

- [Cognee: lokale LLM- und FastEmbed-Konfiguration](https://docs.cognee.ai/getting-started/llm-quickstart-skill)
- [Cognee: Embedding-Provider und Dimensionen](https://docs.cognee.ai/setup-configuration/embedding-providers)
- [Cognee: Ollama-Endpunkt mit `/v1`](https://docs.cognee.ai/setup-configuration/llm-providers)
- [Ollama: lokale Docker-Datenablage](https://docs.ollama.com/docker)
- [Ollama: Cloud-Abschaltung und Ressourcensteuerung](https://docs.ollama.com/faq)
- [FastEmbed: unterstuetzte lokale Modelle](https://qdrant.github.io/fastembed/examples/Supported_Models/)
- [Docker/Moby: Port-Publishing bei ausschliesslich internen Netzen](https://github.com/moby/moby/discussions/53256)
