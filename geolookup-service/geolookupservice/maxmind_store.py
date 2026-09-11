"""MaxMind GeoIP2 reader store with atomic swap and delayed close (no lookup downtime)."""

from __future__ import annotations

import logging
import threading
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from geoip2.database import Reader
from geoip2.errors import AddressNotFoundError, GeoIP2Error

from geolookupservice.config import Settings

log = logging.getLogger(__name__)

CITY_NAME_HINTS = ("City", "city")
ASN_NAME_HINTS = ("ASN", "ISP", "asn", "isp")


@dataclass
class DbFile:
    path: Path
    mtime: float
    size: int

    @classmethod
    def from_path(cls, path: Path) -> DbFile | None:
        if not path.is_file():
            return None
        stat = path.stat()
        return cls(path=path, mtime=stat.st_mtime, size=stat.st_size)


def _iso(ts: float | None) -> str | None:
    if ts is None:
        return None
    return datetime.fromtimestamp(ts, tz=timezone.utc).isoformat()


def discover_db(data_dir: Path, explicit: str, hints: tuple[str, ...]) -> Path | None:
    if explicit:
        path = Path(explicit)
        return path if path.is_file() else None
    if not data_dir.is_dir():
        return None
    preferred = []
    for hint in hints:
        for edition in (f"GeoLite2-{hint}.mmdb", f"GeoIP2-{hint}.mmdb"):
            candidate = data_dir / edition
            if candidate.is_file():
                preferred.append(candidate)
    if preferred:
        return preferred[0]
    matches = sorted(
        path
        for path in data_dir.glob("*.mmdb")
        if any(hint.lower() in path.name.lower() for hint in hints)
    )
    return matches[0] if matches else None


class DatabaseStore:
    """Thread-safe GeoIP2 reader holder.

    Reloads by opening a new Reader on atomically replaced files, swapping
    pointers, then closing the previous Reader after a grace period so
    in-flight lookups are not interrupted.
    """

    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self._lock = threading.RLock()
        self._city: Reader | None = None
        self._asn: Reader | None = None
        self._city_file: DbFile | None = None
        self._asn_file: DbFile | None = None
        self._retired: list[tuple[float, Reader]] = []
        self._last_reload: float | None = None
        self._last_download: float | None = None
        self.reload_count = 0
        self._closed = False

    @property
    def mode(self) -> str:
        return "maxmind" if self._city is not None or self._asn is not None else "fallback"

    @property
    def ready(self) -> bool:
        if self._city is not None or self._asn is not None:
            return True
        return self.settings.maxmind_allow_fallback

    def mark_downloaded(self) -> None:
        self._last_download = time.time()

    def load(self) -> bool:
        """Open readers from disk if MMDB files are present. Returns True if any loaded."""
        data_dir = self.settings.data_dir
        city_path = discover_db(data_dir, self.settings.geoip_city_db_path, CITY_NAME_HINTS)
        asn_path = discover_db(data_dir, self.settings.geoip_asn_db_path, ASN_NAME_HINTS)
        return self._open_paths(city_path, asn_path)

    def reload_if_changed(self) -> bool:
        data_dir = self.settings.data_dir
        city_path = discover_db(data_dir, self.settings.geoip_city_db_path, CITY_NAME_HINTS)
        asn_path = discover_db(data_dir, self.settings.geoip_asn_db_path, ASN_NAME_HINTS)
        city_file = DbFile.from_path(city_path) if city_path else None
        asn_file = DbFile.from_path(asn_path) if asn_path else None
        if self._same(city_file, self._city_file) and self._same(asn_file, self._asn_file):
            self.reap()
            return False
        log.info("MMDB files changed on disk; reloading readers")
        return self._open_paths(city_path, asn_path)

    def _open_paths(self, city_path: Path | None, asn_path: Path | None) -> bool:
        new_city: Reader | None = None
        new_asn: Reader | None = None
        city_file = DbFile.from_path(city_path) if city_path else None
        asn_file = DbFile.from_path(asn_path) if asn_path else None
        try:
            if city_file:
                new_city = Reader(str(city_file.path))
            if asn_file:
                new_asn = Reader(str(asn_file.path))
        except Exception:
            log.exception("Failed to open MaxMind MMDB; keeping previous readers")
            if new_city is not None:
                new_city.close()
            if new_asn is not None:
                new_asn.close()
            # Remember the on-disk fingerprint so we do not retry until it changes.
            with self._lock:
                self._city_file = city_file
                self._asn_file = asn_file
            return False

        now = time.monotonic()
        with self._lock:
            if self._city is not None and self._city is not new_city:
                self._retired.append((now, self._city))
            if self._asn is not None and self._asn is not new_asn:
                self._retired.append((now, self._asn))
            self._city = new_city
            self._asn = new_asn
            self._city_file = city_file
            self._asn_file = asn_file
            if new_city is not None or new_asn is not None:
                self._last_reload = time.time()
                self.reload_count += 1
        if new_city or new_asn:
            log.info(
                "Loaded MaxMind databases city=%s asn=%s",
                city_file.path if city_file else None,
                asn_file.path if asn_file else None,
            )
        self.reap()
        return bool(new_city or new_asn)

    @staticmethod
    def _same(left: DbFile | None, right: DbFile | None) -> bool:
        if left is None and right is None:
            return True
        if left is None or right is None:
            return False
        return left.path == right.path and left.mtime == right.mtime and left.size == right.size

    def readers(self) -> tuple[Reader | None, Reader | None]:
        with self._lock:
            return self._city, self._asn

    def lookup_city(self, ip: str) -> Any | None:
        city_reader, _ = self.readers()
        if city_reader is None:
            return None
        try:
            return city_reader.city(ip)
        except AddressNotFoundError:
            return None
        except GeoIP2Error:
            log.warning("GeoIP2 city lookup failed for %s", ip, exc_info=True)
            return None

    def lookup_asn(self, ip: str) -> Any | None:
        _, asn_reader = self.readers()
        if asn_reader is None:
            return None
        try:
            if hasattr(asn_reader, "asn"):
                return asn_reader.asn(ip)
            return asn_reader.isp(ip)
        except AddressNotFoundError:
            return None
        except GeoIP2Error:
            log.warning("GeoIP2 ASN lookup failed for %s", ip, exc_info=True)
            return None

    def status_payload(self) -> dict[str, Any]:
        with self._lock:
            city_file = self._city_file
            asn_file = self._asn_file
            last_reload = self._last_reload
            last_download = self._last_download
            reload_count = self.reload_count
            mode = self.mode
            ready = self.ready
        return {
            "mode": mode,
            "ready": ready,
            "city_db": str(city_file.path) if city_file else None,
            "asn_db": str(asn_file.path) if asn_file else None,
            "city_db_mtime": _iso(city_file.mtime) if city_file else None,
            "asn_db_mtime": _iso(asn_file.mtime) if asn_file else None,
            "last_reload": _iso(last_reload),
            "last_download": _iso(last_download),
            "reload_count": reload_count,
        }

    def reap(self) -> None:
        grace = self.settings.maxmind_reload_grace_seconds
        now = time.monotonic()
        with self._lock:
            keep: list[tuple[float, Reader]] = []
            for ts, reader in self._retired:
                if now - ts >= grace:
                    try:
                        reader.close()
                    except Exception:
                        log.debug("Error closing retired MaxMind reader", exc_info=True)
                else:
                    keep.append((ts, reader))
            self._retired = keep

    def close(self) -> None:
        self._closed = True
        with self._lock:
            for _, reader in self._retired:
                try:
                    reader.close()
                except Exception:
                    pass
            self._retired.clear()
            if self._city is not None:
                try:
                    self._city.close()
                except Exception:
                    pass
                self._city = None
            if self._asn is not None:
                try:
                    self._asn.close()
                except Exception:
                    pass
                self._asn = None

    def override_readers_for_tests(
        self,
        city_reader: Reader | None,
        asn_reader: Reader | None,
        *,
        city_path: Path | None = None,
        asn_path: Path | None = None,
    ) -> None:
        """Test helper: inject fake readers without touching disk."""
        with self._lock:
            self._city = city_reader
            self._asn = asn_reader
            self._city_file = DbFile.from_path(city_path) if city_path else (
                DbFile(Path("test-city.mmdb"), time.time(), 1) if city_reader else None
            )
            self._asn_file = DbFile.from_path(asn_path) if asn_path else (
                DbFile(Path("test-asn.mmdb"), time.time(), 1) if asn_reader else None
            )
            self._last_reload = time.time()
            self.reload_count += 1
