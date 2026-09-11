"""GeoLookupService HTTP API — MaxMind GeoIP lookups for other services."""

from __future__ import annotations

import asyncio
import logging
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, HTTPException, Query, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse, JSONResponse
from fastapi.staticfiles import StaticFiles

from geolookupservice import __version__
from geolookupservice.catalog import CATALOG
from geolookupservice.config import Settings, get_settings
from geolookupservice.geo import bearing_degrees, haversine_km, km_to_miles, longitude_offset_hours
from geolookupservice.logging_config import configure_logging
from geolookupservice.lookup import lookup_ip
from geolookupservice.maxmind_store import DatabaseStore
from geolookupservice.models import (
    BatchIpRequest,
    BatchIpResponse,
    CityListResponse,
    Coordinates,
    DistanceResponse,
    HealthResponse,
    IpLookupResponse,
    ReadyResponse,
    ReverseLookupResponse,
    SearchResponse,
    StatusResponse,
    TimezoneResponse,
)
from geolookupservice.updater import download_all

log = logging.getLogger(__name__)
STATIC_DIR = Path(__file__).resolve().parent / "static"


def _client_ip(request: Request) -> str:
    forwarded = request.headers.get("x-forwarded-for")
    if forwarded:
        return forwarded.split(",")[0].strip()
    real_ip = request.headers.get("x-real-ip")
    if real_ip:
        return real_ip.strip()
    if request.client and request.client.host:
        return request.client.host
    raise HTTPException(status_code=400, detail="Unable to determine client IP")


def _store(request: Request) -> DatabaseStore:
    return request.app.state.store


def _settings(request: Request) -> Settings:
    return request.app.state.settings


async def _refresh_loop(store: DatabaseStore, settings: Settings) -> None:
    interval = settings.update_interval_seconds
    if interval <= 0 or not settings.has_license:
        return
    while True:
        await asyncio.sleep(interval)
        try:
            report = await asyncio.to_thread(download_all, settings)
            if report.ok:
                store.mark_downloaded()
                await asyncio.to_thread(store.reload_if_changed)
        except asyncio.CancelledError:
            raise
        except Exception:
            log.exception("Periodic MaxMind download failed")


async def _watch_loop(store: DatabaseStore, settings: Settings) -> None:
    interval = max(2.0, settings.maxmind_watch_interval_seconds)
    while True:
        await asyncio.sleep(interval)
        try:
            await asyncio.to_thread(store.reload_if_changed)
        except asyncio.CancelledError:
            raise
        except Exception:
            log.exception("MMDB watch/reload failed")


def create_app(
    settings: Settings | None = None,
    store: DatabaseStore | None = None,
    *,
    start_background: bool = True,
) -> FastAPI:
    settings = settings or get_settings()

    @asynccontextmanager
    async def lifespan(app: FastAPI):
        configure_logging(settings.log_level, settings.log_format)
        db = store or DatabaseStore(settings)
        loaded = db.load()
        app.state.store = db
        app.state.settings = settings
        log.info(
            "GeoLookupService starting version=%s mode=%s loaded=%s data_dir=%s fallback=%s",
            __version__,
            db.mode,
            loaded,
            settings.data_dir,
            settings.maxmind_allow_fallback,
        )
        tasks: list[asyncio.Task] = []
        if start_background:
            if settings.has_license:
                try:
                    report = await asyncio.to_thread(download_all, settings)
                    if report.ok:
                        db.mark_downloaded()
                        db.reload_if_changed()
                except Exception:
                    log.exception("Startup MaxMind download failed; continuing with existing files/fallback")
            tasks.append(asyncio.create_task(_watch_loop(db, settings), name="mmdb-watch"))
            if settings.has_license and settings.update_interval_seconds > 0:
                tasks.append(asyncio.create_task(_refresh_loop(db, settings), name="mmdb-refresh"))
        try:
            yield
        finally:
            for task in tasks:
                task.cancel()
            if tasks:
                await asyncio.gather(*tasks, return_exceptions=True)
            db.close()
            log.info("GeoLookupService stopped")

    app = FastAPI(
        title="GeoLookupService",
        version=__version__,
        description=(
            "Long-running MaxMind GeoIP lookup service. Other servers query "
            "`GET /api/v1/ip` instead of downloading GeoLite2/GeoIP2 databases themselves."
        ),
        lifespan=lifespan,
    )
    app.add_middleware(
        CORSMiddleware,
        allow_origins=settings.cors_origin_list,
        allow_credentials=False,
        allow_methods=["GET", "POST", "OPTIONS"],
        allow_headers=["*"],
    )

    @app.get("/health", response_model=HealthResponse, tags=["ops"])
    def health() -> HealthResponse:
        return HealthResponse(version=__version__)

    @app.get("/ready", response_model=ReadyResponse, tags=["ops"])
    def ready(request: Request):
        db = _store(request)
        payload = db.status_payload()
        if db.ready:
            message = (
                None
                if payload["mode"] == "maxmind"
                else "Serving built-in fallback data until an MMDB is loaded"
            )
        else:
            message = "No MaxMind MMDB loaded and MAXMIND_ALLOW_FALLBACK is false"
        body = ReadyResponse(
            status="ready" if db.ready else "not_ready",
            mode=payload["mode"],
            city_db=payload["city_db"],
            asn_db=payload["asn_db"],
            last_reload=payload["last_reload"],
            last_download=payload["last_download"],
            message=message,
        )
        return JSONResponse(status_code=200 if db.ready else 503, content=body.model_dump())

    @app.get("/api/v1/status", response_model=StatusResponse, tags=["ops"])
    def status(request: Request) -> StatusResponse:
        db = _store(request)
        cfg = _settings(request)
        payload = db.status_payload()
        return StatusResponse(
            version=__version__,
            allow_fallback=cfg.maxmind_allow_fallback,
            has_license_configured=cfg.has_license,
            editions=cfg.editions,
            data_dir=str(cfg.data_dir),
            **payload,
        )

    @app.get("/api/v1/ip", response_model=IpLookupResponse, tags=["geoip"])
    def lookup_one(
        request: Request,
        address: str | None = Query(default=None, description="IPv4 or IPv6; defaults to client IP"),
    ) -> IpLookupResponse:
        target = (address or _client_ip(request)).strip()
        try:
            return lookup_ip(_store(request), target)
        except ValueError as exc:
            raise HTTPException(status_code=400, detail=f"Invalid IP address: {exc}") from exc

    @app.post("/api/v1/ip/batch", response_model=BatchIpResponse, tags=["geoip"])
    def lookup_batch(request: Request, body: BatchIpRequest) -> BatchIpResponse:
        cfg = _settings(request)
        if len(body.addresses) > cfg.batch_max_addresses:
            raise HTTPException(
                status_code=400,
                detail=f"At most {cfg.batch_max_addresses} addresses per request",
            )
        db = _store(request)
        results: list[IpLookupResponse] = []
        for raw in body.addresses:
            try:
                results.append(lookup_ip(db, raw.strip()))
            except ValueError as exc:
                raise HTTPException(status_code=400, detail=f"Invalid IP address '{raw}': {exc}") from exc
        return BatchIpResponse(count=len(results), results=results)

    @app.get("/api/v1/cities", response_model=CityListResponse, tags=["catalog"])
    def list_cities(
        offset: int = Query(default=0, ge=0),
        limit: int = Query(default=50, ge=1, le=200),
        country: str | None = Query(default=None),
    ) -> CityListResponse:
        cities, total = CATALOG.list(offset=offset, limit=limit, country=country)
        return CityListResponse(total=total, offset=offset, limit=limit, cities=cities)

    @app.get("/api/v1/cities/{city_id}", tags=["catalog"])
    def get_city(city_id: str):
        city = CATALOG.get(city_id)
        if city is None:
            raise HTTPException(status_code=404, detail="City not found")
        return city

    @app.get("/api/v1/search", response_model=SearchResponse, tags=["catalog"])
    def search_cities(
        q: str = Query(min_length=1),
        limit: int = Query(default=10, ge=1, le=50),
    ) -> SearchResponse:
        results = CATALOG.search(q, limit=limit)
        return SearchResponse(query=q, count=len(results), results=results)

    @app.get("/api/v1/reverse", response_model=ReverseLookupResponse, tags=["catalog"])
    def reverse(
        lat: float = Query(ge=-90, le=90),
        lon: float = Query(ge=-180, le=180),
    ) -> ReverseLookupResponse:
        city, distance = CATALOG.reverse(lat, lon)
        return ReverseLookupResponse(
            latitude=lat,
            longitude=lon,
            nearest=city,
            distance_km=round(distance, 3),
            approximate=True,
        )

    @app.get("/api/v1/distance", response_model=DistanceResponse, tags=["catalog"])
    def distance(
        from_lat: float | None = Query(default=None, ge=-90, le=90),
        from_lon: float | None = Query(default=None, ge=-180, le=180),
        to_lat: float | None = Query(default=None, ge=-90, le=90),
        to_lon: float | None = Query(default=None, ge=-180, le=180),
        origin: str | None = Query(default=None, description="City id or name"),
        destination: str | None = Query(default=None, description="City id or name"),
    ) -> DistanceResponse:
        if origin and destination:
            start = CATALOG.get(origin) or (CATALOG.search(origin, limit=1)[0:1] or [None])[0]
            end = CATALOG.get(destination) or (CATALOG.search(destination, limit=1)[0:1] or [None])[0]
            if start is None or end is None:
                raise HTTPException(status_code=404, detail="Unknown origin or destination city")
            from_lat, from_lon = start.latitude, start.longitude
            to_lat, to_lon = end.latitude, end.longitude
        if None in (from_lat, from_lon, to_lat, to_lon):
            raise HTTPException(
                status_code=400,
                detail="Provide from_lat/from_lon/to_lat/to_lon or origin/destination city names",
            )
        km = haversine_km(from_lat, from_lon, to_lat, to_lon)
        return DistanceResponse(
            origin=Coordinates(latitude=from_lat, longitude=from_lon),
            destination=Coordinates(latitude=to_lat, longitude=to_lon),
            distance_km=round(km, 3),
            distance_miles=round(km_to_miles(km), 3),
            bearing_degrees=round(bearing_degrees(from_lat, from_lon, to_lat, to_lon), 2),
        )

    @app.get("/api/v1/timezone", response_model=TimezoneResponse, tags=["catalog"])
    def timezone(
        lat: float = Query(ge=-90, le=90),
        lon: float = Query(ge=-180, le=180),
    ) -> TimezoneResponse:
        city, distance = CATALOG.reverse(lat, lon)
        if distance <= 250:
            return TimezoneResponse(
                latitude=lat,
                longitude=lon,
                timezone=city.timezone,
                source="city",
                nearest_city=city,
                offset_hours_estimate=round(longitude_offset_hours(lon), 2),
            )
        return TimezoneResponse(
            latitude=lat,
            longitude=lon,
            timezone=f"UTC{longitude_offset_hours(lon):+.2f}".replace("+", "+").replace("-", "-"),
            source="longitude",
            nearest_city=city,
            offset_hours_estimate=round(longitude_offset_hours(lon), 2),
        )

    if STATIC_DIR.is_dir():
        app.mount("/static", StaticFiles(directory=STATIC_DIR), name="static")

        @app.get("/", include_in_schema=False)
        def index() -> FileResponse:
            return FileResponse(STATIC_DIR / "index.html")

    return app


app = create_app()


def run() -> None:
    import uvicorn

    settings = get_settings()
    configure_logging(settings.log_level, settings.log_format)
    uvicorn.run(
        "geolookupservice.app:app",
        host=settings.host,
        port=settings.port,
        log_level=settings.log_level.lower(),
        access_log=True,
    )
