# GeoLookupService

Long-running HTTP service that other servers query for **IP → geo** (city, country, lat/long, timezone, ISP/ASN) using local [MaxMind](https://www.maxmind.com/en/home) GeoLite2 / GeoIP2 `.mmdb` files.

It replaces the old workflow of copying MaxMind databases onto every host. One service downloads and refreshes the databases; everyone else calls the API.

## Why this exists

| Before | After |
| --- | --- |
| Each app downloads GeoLite2-City / ASN (or someone copies files by hand) | GeoLookupService downloads on a schedule into a volume |
| Lookups happen in-process and go stale when the DB is not updated | Other services `GET /api/v1/ip?address=` |
| Restart required to pick up a new MMDB | Readers are swapped in place; in-flight requests keep the previous reader until a grace period |

## Architecture

```mermaid
flowchart LR
  apps[Other servers] -->|HTTP GET /api/v1/ip| svc[GeoLookupService]
  svc --> readers[geoip2 Reader]
  readers --> pvc["/data/maxmind *.mmdb"]
  maxmind[MaxMind download API] -->|license key| updater[Updater loop / init container]
  updater --> pvc
```

- **API process** (`python -m geolookupservice`) serves FastAPI, loads `geoip2` readers, and watches MMDB mtimes.
- **Updater** (`python -m geolookupservice.updater`) pulls `GeoLite2-City` and `GeoLite2-ASN` (configurable) from MaxMind, verifies sha256 when available, and atomically replaces files on disk.
- If no MMDB is present, the service can run in an explicit **fallback** mode with a tiny demo dataset (public resolvers such as `8.8.8.8`). That path is for local bring-up and tests — not production accuracy.

## API

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/health` | Liveness. Always 200 if the process is up. |
| `GET` | `/ready` | Readiness. 200 if an MMDB is loaded **or** fallback is enabled. 503 otherwise. |
| `GET` | `/api/v1/status` | Mode, DB paths, last reload/download, whether a license is configured. |
| `GET` | `/api/v1/ip?address=` | Lookup one IPv4/IPv6. Omit `address` to use `X-Forwarded-For` / `X-Real-IP` / peer IP. |
| `POST` | `/api/v1/ip/batch` | Body `{"addresses": ["8.8.8.8", "1.1.1.1"]}` (max 50). |
| `GET` | `/api/v1/search?q=` | City catalog search (demo dataset). |
| `GET` | `/api/v1/reverse?lat=&lon=` | Nearest catalog city. |
| `GET` | `/api/v1/distance` | Great-circle distance (`from_lat`… or `origin`/`destination` city names). |
| `GET` | `/docs` | OpenAPI. |

Example:

```bash
curl -s "http://geolookup.internal/api/v1/ip?address=8.8.8.8"
```

```json
{
  "ip": "8.8.8.8",
  "version": 4,
  "network_type": "public",
  "is_private": false,
  "found": true,
  "source": "maxmind",
  "location": {
    "city": "Mountain View",
    "region": "California",
    "country": "United States",
    "country_code": "US",
    "latitude": 37.4056,
    "longitude": -122.0775,
    "timezone": "America/Los_Angeles"
  },
  "isp": "Google LLC",
  "org": "Google LLC",
  "asn": 15169
}
```

From another service, treat GeoLookupService as a cluster-local dependency (Kubernetes Service DNS `geolookup.geolookup.svc.cluster.local`).

## Environment variables

| Variable | Default | Notes |
| --- | --- | --- |
| `MAXMIND_LICENSE_KEY` | empty | Required to download databases. **Never commit a real key.** |
| `MAXMIND_ACCOUNT_ID` | empty | Used with the permalink download API (`Basic` auth). If unset, the legacy `license_key` query URL is used. |
| `MAXMIND_DATA_DIR` | `/data/maxmind` | Persistent volume path for `.mmdb` files. |
| `MAXMIND_EDITIONS` | `GeoLite2-City,GeoLite2-ASN` | Comma-separated MaxMind edition IDs. Paid `GeoIP2-City` works the same way if your license includes it. |
| `MAXMIND_UPDATE_INTERVAL_HOURS` | `24` | In-process refresh interval. `0` disables the loop. |
| `MAXMIND_ALLOW_FALLBACK` | `true` | Set `false` in production so `/ready` fails closed until an MMDB is loaded. |
| `GEOIP_CITY_DB_PATH` / `GEOIP_ASN_DB_PATH` | discovered | Optional explicit file paths. |
| `HOST` / `PORT` | `0.0.0.0` / `8080` | Bind address. |
| `LOG_LEVEL` / `LOG_FORMAT` | `info` / `text` | `LOG_FORMAT=json` for production. |
| `CORS_ORIGINS` | `*` | Comma-separated list, or `*`. |

Copy `.env.example` to `.env` for local runs.

You need a MaxMind account and a GeoLite2 license key: [GeoLite2 signup](https://www.maxmind.com/en/geolite2/signup). Accept the GeoLite2 license in the MaxMind portal or downloads return 403.

## Local run (no Docker)

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements-dev.txt
export MAXMIND_DATA_DIR="$PWD/data/maxmind"
export MAXMIND_ALLOW_FALLBACK=true
python -m geolookupservice
```

Without a license key the API starts in fallback mode. With a key, it downloads City + ASN into `MAXMIND_DATA_DIR` on startup and every `MAXMIND_UPDATE_INTERVAL_HOURS`.

One-shot download (init container / CronJob):

```bash
python -m geolookupservice.updater
```

## Docker

```bash
docker compose up --build
# or
docker build -t geolookupservice:0.1.0 .
docker run --rm -p 8080:8080 \
  -e MAXMIND_ALLOW_FALLBACK=true \
  -v geolookup-maxmind:/data/maxmind \
  geolookupservice:0.1.0
```

Pass secrets via env or an env file — not image layers:

```bash
docker run --rm -p 8080:8080 \
  -e MAXMIND_ACCOUNT_ID \
  -e MAXMIND_LICENSE_KEY \
  -e MAXMIND_ALLOW_FALLBACK=false \
  -v geolookup-maxmind:/data/maxmind \
  geolookupservice:0.1.0
```

MMDB files persist on the `maxmind-data` volume (`/data/maxmind`).

## Kubernetes (no Helm)

Build and make the image available to the cluster, then:

```bash
# 1. Put real credentials in the example secret (do not commit them)
#    or create the secret separately:
kubectl create namespace geolookup --dry-run=client -o yaml | kubectl apply -f -
kubectl -n geolookup create secret generic geolookup-maxmind \
  --from-literal=MAXMIND_ACCOUNT_ID="$MAXMIND_ACCOUNT_ID" \
  --from-literal=MAXMIND_LICENSE_KEY="$MAXMIND_LICENSE_KEY" \
  --dry-run=client -o yaml | kubectl apply -f -

# 2. Point kustomization at your registry tag if needed, then:
kubectl apply -k k8s/
```

What `k8s/` includes:

- **Deployment** with an **init container** that runs `python -m geolookupservice.updater` into the PVC, then the API container. Liveness `/health`, readiness `/ready`. Resource requests/limits set.
- **Service** `geolookup` (ClusterIP 80 → 8080).
- **ConfigMap** for non-secret settings (`MAXMIND_ALLOW_FALLBACK=false` for production).
- **Secret** example (`k8s/secret.yaml`) — replace placeholders.
- **PVC** `1Gi` for `/data/maxmind`.
- Optional **CronJob** (`k8s/cronjob.yaml`) weekly refresh. Apply it only if the volume is ReadWriteMany; on RWO the in-process updater plus init container is the supported path.

The API watches the volume and **reloads readers without dropping the process** when files change (atomic replace + delayed close of the previous `geoip2` reader).

## Tests

Tests do **not** need a MaxMind license. They use a mocked `geoip2` reader, a synthetic tar.gz for the downloader, and the fallback dataset.

```bash
pip install -r requirements-dev.txt
pytest
```

## Layout

```
geolookupservice/   FastAPI app, MaxMind store, updater, fallback catalog
tests/              pytest (no live MaxMind account)
Dockerfile
docker-compose.yml
k8s/                helm-less manifests + kustomization
```
