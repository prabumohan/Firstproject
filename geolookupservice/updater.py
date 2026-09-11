"""Download GeoLite2/GeoIP2 databases from MaxMind and atomically replace local MMDB files."""

from __future__ import annotations

import hashlib
import io
import logging
import os
import tarfile
import tempfile
from dataclasses import dataclass, field
from pathlib import Path

import httpx

from geolookupservice.config import Settings

log = logging.getLogger(__name__)

USER_AGENT = "GeoLookupService/0.1.0 (+https://github.com/prabumohan/Firstproject)"
PERMALINK = "https://download.maxmind.com/geoip/databases/{edition}/download"
LEGACY = "https://download.maxmind.com/app/geoip_download"


@dataclass
class EditionResult:
    edition: str
    ok: bool
    path: Path | None = None
    skipped: bool = False
    not_modified: bool = False
    error: str | None = None


@dataclass
class DownloadReport:
    ok: bool
    results: list[EditionResult] = field(default_factory=list)
    message: str = ""


def _redact(value: str) -> str:
    if not value:
        return ""
    if len(value) <= 4:
        return "****"
    return value[:2] + "…" + value[-2:]


def download_all(settings: Settings) -> DownloadReport:
    if not settings.has_license:
        msg = (
            "MAXMIND_LICENSE_KEY is not set; skipping download. "
            "Place .mmdb files in the data dir or enable fallback mode."
        )
        log.warning(msg)
        return DownloadReport(ok=True, message=msg)

    settings.data_dir.mkdir(parents=True, exist_ok=True)
    results: list[EditionResult] = []
    timeout = httpx.Timeout(settings.maxmind_download_timeout_seconds)
    with httpx.Client(timeout=timeout, follow_redirects=True, headers={"User-Agent": USER_AGENT}) as client:
        for edition in settings.editions:
            results.append(_download_edition(client, settings, edition))

    ok = all(item.ok for item in results)
    downloaded = [item.edition for item in results if item.ok and not item.skipped and not item.not_modified]
    message = (
        f"Downloaded {', '.join(downloaded)}" if downloaded else "No database files needed downloading"
    )
    if not ok:
        errors = [f"{item.edition}: {item.error}" for item in results if not item.ok]
        message = "; ".join(errors)
        log.error("MaxMind download finished with errors: %s", message)
    else:
        log.info(message)
    return DownloadReport(ok=ok, results=results, message=message)


def _download_edition(client: httpx.Client, settings: Settings, edition: str) -> EditionResult:
    dest = settings.data_dir / f"{edition}.mmdb"
    metadata_path = settings.data_dir / f"{edition}.metadata"
    last_modified = metadata_path.read_text(encoding="utf-8").strip() if metadata_path.is_file() else ""

    url, auth = _request_for(settings, edition, suffix="tar.gz")
    headers = {}
    if last_modified:
        headers["If-Modified-Since"] = last_modified

    log.info("Downloading MaxMind edition %s", edition)
    try:
        response = client.get(url, auth=auth, headers=headers)
    except httpx.HTTPError as exc:
        return EditionResult(edition=edition, ok=False, error=f"network error: {exc}")

    if response.status_code == 304:
        log.info("%s is already up to date (HTTP 304)", edition)
        return EditionResult(edition=edition, ok=True, path=dest, not_modified=True)

    if response.status_code == 401:
        return EditionResult(
            edition=edition,
            ok=False,
            error="authentication failed (check MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY)",
        )
    if response.status_code == 403:
        return EditionResult(
            edition=edition,
            ok=False,
            error="download forbidden — license may lack this edition or GeoLite2 agreement not accepted",
        )
    if response.status_code != 200:
        return EditionResult(
            edition=edition,
            ok=False,
            error=f"unexpected HTTP {response.status_code}",
        )

    archive = response.content
    expected = _fetch_sha256(client, settings, edition)
    if expected:
        digest = hashlib.sha256(archive).hexdigest()
        if digest.lower() != expected.lower():
            return EditionResult(edition=edition, ok=False, error="sha256 mismatch")

    try:
        mmdb_bytes, member_name = _extract_mmdb(archive)
    except ValueError as exc:
        return EditionResult(edition=edition, ok=False, error=str(exc))

    _atomic_write(dest, mmdb_bytes)
    modified = response.headers.get("Last-Modified", "")
    if modified:
        metadata_path.write_text(modified, encoding="utf-8")
    log.info("Wrote %s (%s, %d bytes)", dest, member_name, len(mmdb_bytes))
    return EditionResult(edition=edition, ok=True, path=dest)


def _request_for(settings: Settings, edition: str, suffix: str) -> tuple[str, tuple[str, str] | None]:
    account = settings.maxmind_account_id.strip()
    key = settings.maxmind_license_key.strip()
    if account:
        url = f"{PERMALINK.format(edition=edition)}?suffix={suffix}"
        return url, (account, key)
    url = f"{LEGACY}?edition_id={edition}&license_key={key}&suffix={suffix}"
    return url, None


def _fetch_sha256(client: httpx.Client, settings: Settings, edition: str) -> str | None:
    url, auth = _request_for(settings, edition, suffix="tar.gz.sha256")
    try:
        response = client.get(url, auth=auth)
        if response.status_code != 200:
            log.warning("No sha256 checksum for %s (HTTP %s)", edition, response.status_code)
            return None
        text = response.text.strip().split()[0]
        return text if text else None
    except httpx.HTTPError:
        log.warning("Failed to fetch sha256 for %s", edition, exc_info=True)
        return None


def _extract_mmdb(archive: bytes) -> tuple[bytes, str]:
    with tarfile.open(fileobj=io.BytesIO(archive), mode="r:gz") as tar:
        for member in tar.getmembers():
            name = Path(member.name).name
            if member.isfile() and name.endswith(".mmdb"):
                extracted = tar.extractfile(member)
                if extracted is None:
                    continue
                return extracted.read(), name
    raise ValueError("archive did not contain an .mmdb file")


def _atomic_write(dest: Path, payload: bytes) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp_name = tempfile.mkstemp(prefix=dest.name, suffix=".tmp", dir=str(dest.parent))
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp_name, dest)
    except Exception:
        try:
            os.unlink(tmp_name)
        except OSError:
            pass
        raise


def main() -> None:
    from geolookupservice.config import get_settings
    from geolookupservice.logging_config import configure_logging

    settings = get_settings()
    configure_logging(settings.log_level, settings.log_format)
    if settings.has_license:
        log.info(
            "Starting MaxMind download account_id=%s license=%s editions=%s dest=%s",
            settings.maxmind_account_id or "(legacy URL)",
            _redact(settings.maxmind_license_key),
            ",".join(settings.editions),
            settings.data_dir,
        )
    report = download_all(settings)
    print(report.message)
    raise SystemExit(0 if report.ok else 1)


if __name__ == "__main__":
    main()
