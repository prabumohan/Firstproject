"""Tiny demo dataset used only when no MaxMind MMDB is loaded.

Locations for anycast DNS IPs are approximate and clearly marked as fallback.
"""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class FallbackRecord:
    city: str
    region: str
    region_code: str
    country: str
    country_code: str
    continent: str
    continent_code: str
    latitude: float
    longitude: float
    timezone: str
    isp: str
    org: str
    asn: int


# Public resolver IPs only — enough for local demos without a license key.
FALLBACK_BY_IP: dict[str, FallbackRecord] = {
    "8.8.8.8": FallbackRecord(
        "Mountain View", "California", "CA", "United States", "US",
        "North America", "NA", 37.4056, -122.0775, "America/Los_Angeles",
        "Google LLC", "Google LLC", 15169,
    ),
    "8.8.4.4": FallbackRecord(
        "Mountain View", "California", "CA", "United States", "US",
        "North America", "NA", 37.4056, -122.0775, "America/Los_Angeles",
        "Google LLC", "Google LLC", 15169,
    ),
    "1.1.1.1": FallbackRecord(
        "Los Angeles", "California", "CA", "United States", "US",
        "North America", "NA", 34.0522, -118.2437, "America/Los_Angeles",
        "Cloudflare, Inc.", "Cloudflare, Inc.", 13335,
    ),
    "1.0.0.1": FallbackRecord(
        "Los Angeles", "California", "CA", "United States", "US",
        "North America", "NA", 34.0522, -118.2437, "America/Los_Angeles",
        "Cloudflare, Inc.", "Cloudflare, Inc.", 13335,
    ),
    "9.9.9.9": FallbackRecord(
        "Berkeley", "California", "CA", "United States", "US",
        "North America", "NA", 37.8698, -122.2708, "America/Los_Angeles",
        "Quad9", "Quad9", 19281,
    ),
    "208.67.222.222": FallbackRecord(
        "San Francisco", "California", "CA", "United States", "US",
        "North America", "NA", 37.7749, -122.4194, "America/Los_Angeles",
        "Cisco OpenDNS", "Cisco OpenDNS, LLC", 36692,
    ),
    "208.67.220.220": FallbackRecord(
        "San Francisco", "California", "CA", "United States", "US",
        "North America", "NA", 37.7749, -122.4194, "America/Los_Angeles",
        "Cisco OpenDNS", "Cisco OpenDNS, LLC", 36692,
    ),
    "2001:4860:4860::8888": FallbackRecord(
        "Mountain View", "California", "CA", "United States", "US",
        "North America", "NA", 37.4056, -122.0775, "America/Los_Angeles",
        "Google LLC", "Google LLC", 15169,
    ),
    "2606:4700:4700::1111": FallbackRecord(
        "Los Angeles", "California", "CA", "United States", "US",
        "North America", "NA", 34.0522, -118.2437, "America/Los_Angeles",
        "Cloudflare, Inc.", "Cloudflare, Inc.", 13335,
    ),
}


def lookup_fallback(ip: str) -> FallbackRecord | None:
    return FALLBACK_BY_IP.get(ip)
