"""In-memory world city catalog for search, reverse geocode, and timezone hints."""

from __future__ import annotations

from geolookupservice.cities import ALIASES, all_cities
from geolookupservice.geo import haversine_km
from geolookupservice.models import City


class CityCatalog:
    def __init__(self) -> None:
        self._cities = all_cities()
        self._by_id = {city.id: city for city in self._cities}

    def get(self, city_id: str) -> City | None:
        key = city_id.strip().lower().replace(" ", "-")
        alias = ALIASES.get(city_id.strip().lower())
        return self._by_id.get(key) or self._by_id.get(alias or "")

    def list(
        self,
        *,
        offset: int = 0,
        limit: int = 50,
        country: str | None = None,
    ) -> tuple[list[City], int]:
        rows = self._cities
        if country:
            code = country.strip().upper()
            rows = [
                city
                for city in rows
                if city.country_code == code or city.country.lower() == country.strip().lower()
            ]
        total = len(rows)
        return rows[offset : offset + limit], total

    def search(self, query: str, limit: int = 10) -> list[City]:
        needle = query.strip().lower()
        if not needle:
            return []
        alias_id = ALIASES.get(needle)
        if alias_id and alias_id in self._by_id:
            return [self._by_id[alias_id]]

        scored: list[tuple[int, City]] = []
        for city in self._cities:
            haystacks = (
                city.id,
                city.name.lower(),
                (city.region or "").lower(),
                city.country.lower(),
                city.country_code.lower(),
            )
            if needle == city.id or needle == city.name.lower():
                scored.append((0, city))
            elif any(item.startswith(needle) for item in haystacks if item):
                scored.append((1, city))
            elif any(needle in item for item in haystacks if item):
                scored.append((2, city))
        scored.sort(key=lambda pair: (pair[0], -(pair[1].population or 0), pair[1].name))
        seen: set[str] = set()
        results: list[City] = []
        for _, city in scored:
            if city.id in seen:
                continue
            seen.add(city.id)
            results.append(city)
            if len(results) >= limit:
                break
        return results

    def reverse(self, latitude: float, longitude: float) -> tuple[City, float]:
        best: City | None = None
        best_km = float("inf")
        for city in self._cities:
            distance = haversine_km(latitude, longitude, city.latitude, city.longitude)
            if distance < best_km:
                best = city
                best_km = distance
        assert best is not None
        return best, best_km


CATALOG = CityCatalog()
