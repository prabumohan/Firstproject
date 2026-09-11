from __future__ import annotations

from types import SimpleNamespace

from geoip2.errors import AddressNotFoundError


def _subdivisions(name: str | None, iso: str | None):
    if not name and not iso:
        return None
    most = SimpleNamespace(name=name, iso_code=iso)
    return SimpleNamespace(most_specific=most)


def city_model(
    *,
    city: str | None = "London",
    region: str | None = "England",
    region_code: str | None = "ENG",
    country: str = "United Kingdom",
    country_code: str = "GB",
    continent: str = "Europe",
    continent_code: str = "EU",
    postal: str | None = "EC2A",
    latitude: float = 51.5142,
    longitude: float = -0.0931,
    timezone: str = "Europe/London",
    accuracy_radius: int = 10,
    network: str = "81.2.69.160/27",
):
    return SimpleNamespace(
        city=SimpleNamespace(name=city),
        subdivisions=_subdivisions(region, region_code),
        country=SimpleNamespace(name=country, iso_code=country_code),
        continent=SimpleNamespace(name=continent, code=continent_code),
        postal=SimpleNamespace(code=postal),
        location=SimpleNamespace(
            latitude=latitude,
            longitude=longitude,
            time_zone=timezone,
            accuracy_radius=accuracy_radius,
        ),
        traits=SimpleNamespace(network=network),
    )


def asn_model(*, number: int = 15169, org: str = "Google LLC", network: str = "8.8.8.0/24"):
    return SimpleNamespace(
        autonomous_system_number=number,
        autonomous_system_organization=org,
        network=network,
    )


class FakeCityReader:
    def __init__(self, mapping: dict[str, object] | None = None) -> None:
        self.mapping = mapping or {"81.2.69.160": city_model()}
        self.closed = False

    def city(self, ip: str):
        if ip in self.mapping:
            return self.mapping[ip]
        raise AddressNotFoundError(ip)

    def close(self) -> None:
        self.closed = True


class FakeAsnReader:
    def __init__(self, mapping: dict[str, object] | None = None) -> None:
        self.mapping = mapping or {
            "81.2.69.160": asn_model(number=12345, org="Test ISP", network="81.2.69.160/27"),
            "8.8.8.8": asn_model(),
        }
        self.closed = False

    def asn(self, ip: str):
        if ip in self.mapping:
            return self.mapping[ip]
        raise AddressNotFoundError(ip)

    def close(self) -> None:
        self.closed = True
