from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field


class HealthResponse(BaseModel):
    status: Literal["ok"] = "ok"
    service: str = "geolookupservice"
    version: str


class ReadyResponse(BaseModel):
    status: Literal["ready", "not_ready"]
    mode: Literal["maxmind", "fallback"]
    city_db: str | None = None
    asn_db: str | None = None
    last_reload: str | None = None
    last_download: str | None = None
    message: str | None = None


class StatusResponse(BaseModel):
    service: str = "geolookupservice"
    version: str
    mode: Literal["maxmind", "fallback"]
    ready: bool
    allow_fallback: bool
    has_license_configured: bool
    city_db: str | None = None
    asn_db: str | None = None
    city_db_mtime: str | None = None
    asn_db_mtime: str | None = None
    last_reload: str | None = None
    last_download: str | None = None
    reload_count: int = 0
    editions: list[str] = Field(default_factory=list)
    data_dir: str


class Coordinates(BaseModel):
    latitude: float
    longitude: float


class City(BaseModel):
    id: str
    name: str
    country: str
    country_code: str = Field(min_length=2, max_length=2)
    region: str | None = None
    latitude: float
    longitude: float
    timezone: str
    population: int | None = None


class CityListResponse(BaseModel):
    total: int
    offset: int
    limit: int
    cities: list[City]


class SearchResponse(BaseModel):
    query: str
    count: int
    results: list[City]


class ReverseLookupResponse(BaseModel):
    latitude: float
    longitude: float
    nearest: City
    distance_km: float
    approximate: bool


class IpLocation(BaseModel):
    city: str | None = None
    region: str | None = None
    region_code: str | None = None
    country: str | None = None
    country_code: str | None = None
    continent: str | None = None
    continent_code: str | None = None
    postal: str | None = None
    latitude: float | None = None
    longitude: float | None = None
    timezone: str | None = None
    accuracy_radius_km: int | None = None


class IpLookupResponse(BaseModel):
    ip: str
    version: Literal[4, 6]
    network_type: str
    is_private: bool
    found: bool
    source: Literal["maxmind", "fallback"]
    location: IpLocation | None = None
    isp: str | None = None
    org: str | None = None
    asn: int | None = None
    network: str | None = None
    message: str | None = None


class BatchIpRequest(BaseModel):
    addresses: list[str] = Field(min_length=1, max_length=50)


class BatchIpResponse(BaseModel):
    count: int
    results: list[IpLookupResponse]


class DistanceResponse(BaseModel):
    origin: Coordinates
    destination: Coordinates
    distance_km: float
    distance_miles: float
    bearing_degrees: float


class TimezoneResponse(BaseModel):
    latitude: float
    longitude: float
    timezone: str
    source: Literal["city", "longitude"]
    nearest_city: City | None = None
    offset_hours_estimate: float | None = None
