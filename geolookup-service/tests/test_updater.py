from __future__ import annotations

import hashlib
import io
import tarfile

import httpx

from geolookupservice.config import Settings
from geolookupservice.updater import _download_edition, download_all


def _archive_with_mmdb(payload: bytes = b"mmdb-bytes") -> bytes:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as tar:
        info = tarfile.TarInfo(name="GeoLite2-City_20200101/GeoLite2-City.mmdb")
        info.size = len(payload)
        tar.addfile(info, io.BytesIO(payload))
    return buffer.getvalue()


def test_download_skipped_without_license(tmp_path):
    settings = Settings(maxmind_license_key="", maxmind_data_dir=str(tmp_path))
    report = download_all(settings)
    assert report.ok is True
    assert "MAXMIND_LICENSE_KEY" in report.message
    assert list(tmp_path.glob("*.mmdb")) == []


def test_download_extracts_and_writes_atomically(tmp_path):
    archive = _archive_with_mmdb(b"city-database")
    digest = hashlib.sha256(archive).hexdigest()

    def handler(request: httpx.Request) -> httpx.Response:
        if "suffix=tar.gz.sha256" in str(request.url):
            return httpx.Response(200, text=f"{digest}  GeoLite2-City.tar.gz")
        return httpx.Response(
            200,
            content=archive,
            headers={"Last-Modified": "Wed, 01 Jan 2020 00:00:00 GMT"},
        )

    settings = Settings(
        maxmind_license_key="unit-test-key",
        maxmind_account_id="1234",
        maxmind_data_dir=str(tmp_path),
        maxmind_editions="GeoLite2-City",
    )
    transport = httpx.MockTransport(handler)
    with httpx.Client(transport=transport) as client:
        result = _download_edition(client, settings, "GeoLite2-City")

    assert result.ok is True
    dest = tmp_path / "GeoLite2-City.mmdb"
    assert dest.read_bytes() == b"city-database"
    assert (tmp_path / "GeoLite2-City.metadata").read_text() == "Wed, 01 Jan 2020 00:00:00 GMT"


def test_download_not_modified(tmp_path):
    dest = tmp_path / "GeoLite2-City.mmdb"
    dest.write_bytes(b"existing")
    (tmp_path / "GeoLite2-City.metadata").write_text("Wed, 01 Jan 2020 00:00:00 GMT")

    def handler(request: httpx.Request) -> httpx.Response:
        assert request.headers.get("If-Modified-Since") == "Wed, 01 Jan 2020 00:00:00 GMT"
        return httpx.Response(304)

    settings = Settings(
        maxmind_license_key="unit-test-key",
        maxmind_account_id="1234",
        maxmind_data_dir=str(tmp_path),
        maxmind_editions="GeoLite2-City",
    )
    with httpx.Client(transport=httpx.MockTransport(handler)) as client:
        result = _download_edition(client, settings, "GeoLite2-City")
    assert result.ok is True
    assert result.not_modified is True
    assert dest.read_bytes() == b"existing"


def test_download_auth_failure(tmp_path):
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(401)

    settings = Settings(
        maxmind_license_key="bad",
        maxmind_account_id="1",
        maxmind_data_dir=str(tmp_path),
        maxmind_editions="GeoLite2-City",
    )
    with httpx.Client(transport=httpx.MockTransport(handler)) as client:
        result = _download_edition(client, settings, "GeoLite2-City")
    assert result.ok is False
    assert "authentication" in (result.error or "")
