"""Resolve an IP address to geo + ASN using MaxMind, with an explicit fallback path."""

from __future__ import annotations

import ipaddress

from geolookupservice.fallback import lookup_fallback
from geolookupservice.maxmind_store import DatabaseStore
from geolookupservice.models import IpLocation, IpLookupResponse


def classify_ip(ip: str) -> tuple[ipaddress.IPv4Address | ipaddress.IPv6Address, str, bool, int]:
    parsed = ipaddress.ip_address(ip)
    version = parsed.version
    if parsed.is_loopback:
        kind = "loopback"
    elif parsed.is_link_local:
        kind = "link_local"
    elif parsed.is_multicast:
        kind = "multicast"
    elif parsed.is_unspecified:
        kind = "unspecified"
    elif parsed.is_reserved:
        kind = "reserved"
    elif parsed.is_private:
        kind = "private"
    else:
        kind = "public"
    is_private = parsed.is_private or parsed.is_loopback or parsed.is_link_local or parsed.is_unspecified
    return parsed, kind, is_private, version


def lookup_ip(store: DatabaseStore, address: str) -> IpLookupResponse:
    parsed, network_type, is_private, version = classify_ip(address)
    canonical = str(parsed)
    city_reader, asn_reader = store.readers()
    using_maxmind = city_reader is not None or asn_reader is not None

    if using_maxmind:
        return _from_maxmind(store, canonical, version, network_type, is_private)
    return _from_fallback(canonical, version, network_type, is_private)


def _from_maxmind(
    store: DatabaseStore,
    ip: str,
    version: int,
    network_type: str,
    is_private: bool,
) -> IpLookupResponse:
    city_model = store.lookup_city(ip)
    asn_model = store.lookup_asn(ip)
    location = None
    if city_model is not None:
        loc = city_model.location
        subdivision = city_model.subdivisions.most_specific if city_model.subdivisions else None
        location = IpLocation(
            city=city_model.city.name,
            region=subdivision.name if subdivision else None,
            region_code=subdivision.iso_code if subdivision else None,
            country=city_model.country.name,
            country_code=city_model.country.iso_code,
            continent=city_model.continent.name,
            continent_code=city_model.continent.code,
            postal=city_model.postal.code if city_model.postal else None,
            latitude=loc.latitude if loc else None,
            longitude=loc.longitude if loc else None,
            timezone=loc.time_zone if loc else None,
            accuracy_radius_km=loc.accuracy_radius if loc else None,
        )

    asn = None
    org = None
    isp = None
    network = None
    if asn_model is not None:
        asn = getattr(asn_model, "autonomous_system_number", None)
        org = getattr(asn_model, "autonomous_system_organization", None) or getattr(asn_model, "organization", None)
        isp = getattr(asn_model, "isp", None) or org
        net = getattr(asn_model, "network", None)
        network = str(net) if net is not None else None
    if city_model is not None and network is None:
        net = getattr(city_model.traits, "network", None)
        network = str(net) if net is not None else None

    found = location is not None or asn is not None
    return IpLookupResponse(
        ip=ip,
        version=version,  # type: ignore[arg-type]
        network_type=network_type,
        is_private=is_private,
        found=found,
        source="maxmind",
        location=location,
        isp=isp,
        org=org,
        asn=asn,
        network=network,
        message=None if found else "Address not present in MaxMind databases",
    )


def _from_fallback(ip: str, version: int, network_type: str, is_private: bool) -> IpLookupResponse:
    record = lookup_fallback(ip)
    if record is None:
        return IpLookupResponse(
            ip=ip,
            version=version,  # type: ignore[arg-type]
            network_type=network_type,
            is_private=is_private,
            found=False,
            source="fallback",
            message=(
                "Fallback dataset has no record for this address. "
                "Load a MaxMind MMDB (set MAXMIND_LICENSE_KEY) for production lookups."
            ),
        )
    return IpLookupResponse(
        ip=ip,
        version=version,  # type: ignore[arg-type]
        network_type=network_type,
        is_private=is_private,
        found=True,
        source="fallback",
        location=IpLocation(
            city=record.city,
            region=record.region,
            region_code=record.region_code,
            country=record.country,
            country_code=record.country_code,
            continent=record.continent,
            continent_code=record.continent_code,
            latitude=record.latitude,
            longitude=record.longitude,
            timezone=record.timezone,
        ),
        isp=record.isp,
        org=record.org,
        asn=record.asn,
        message="Using built-in fallback dataset; MaxMind MMDB is not loaded",
    )
