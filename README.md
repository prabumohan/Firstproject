# Firstproject

Hello-world repo plus **GeoLookupService**, a MaxMind-backed IP geolocation HTTP API.

All service code, Docker files, tests, and Kubernetes manifests live in:

**[`geolookup-service/`](geolookup-service/)**

## Run locally with Docker (no MaxMind key required)

```bash
cd geolookup-service
cp .env.example .env
docker compose up --build
```

- UI and API: http://127.0.0.1:8080
- Health: `curl -s http://127.0.0.1:8080/health`
- Lookup: `curl -s "http://127.0.0.1:8080/api/v1/ip?address=8.8.8.8"`

Leave `MAXMIND_LICENSE_KEY` empty to start immediately in fallback mode. Put a GeoLite2 key in `.env` and Compose will download City + ASN into the `geolookup-maxmind-data` volume.

See [`geolookup-service/README.md`](geolookup-service/README.md) for the API, env vars, tests, and Kubernetes.
