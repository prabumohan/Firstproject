"""Great-circle helpers used by reverse geocoding and distance endpoints."""

from __future__ import annotations

import math

EARTH_RADIUS_KM = 6371.0088
MILES_PER_KM = 0.621371


def haversine_km(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Return the great-circle distance in kilometres between two WGS84 points."""
    phi1, phi2 = math.radians(lat1), math.radians(lat2)
    d_phi = math.radians(lat2 - lat1)
    d_lambda = math.radians(lon2 - lon1)
    a = math.sin(d_phi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(d_lambda / 2) ** 2
    return 2 * EARTH_RADIUS_KM * math.atan2(math.sqrt(a), math.sqrt(max(0.0, 1 - a)))


def bearing_degrees(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Initial bearing from origin to destination, in degrees clockwise from north."""
    phi1, phi2 = math.radians(lat1), math.radians(lat2)
    d_lambda = math.radians(lon2 - lon1)
    x = math.sin(d_lambda) * math.cos(phi2)
    y = math.cos(phi1) * math.sin(phi2) - math.sin(phi1) * math.cos(phi2) * math.cos(d_lambda)
    return (math.degrees(math.atan2(x, y)) + 360) % 360


def km_to_miles(km: float) -> float:
    return km * MILES_PER_KM


def longitude_offset_hours(longitude: float) -> float:
    """Rough solar timezone offset from longitude (15 degrees per hour)."""
    return max(-12.0, min(14.0, longitude / 15.0))
