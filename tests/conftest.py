from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from geolookupservice.app import create_app
from geolookupservice.config import Settings, reset_settings_cache
from geolookupservice.maxmind_store import DatabaseStore


@pytest.fixture
def data_dir(tmp_path):
    directory = tmp_path / "maxmind"
    directory.mkdir()
    return directory


@pytest.fixture
def settings(data_dir, monkeypatch):
    monkeypatch.setenv("MAXMIND_DATA_DIR", str(data_dir))
    monkeypatch.setenv("MAXMIND_ALLOW_FALLBACK", "true")
    monkeypatch.setenv("MAXMIND_LICENSE_KEY", "")
    monkeypatch.setenv("MAXMIND_ACCOUNT_ID", "")
    monkeypatch.setenv("MAXMIND_UPDATE_INTERVAL_HOURS", "0")
    monkeypatch.setenv("LOG_LEVEL", "warning")
    reset_settings_cache()
    return Settings(
        maxmind_data_dir=str(data_dir),
        maxmind_allow_fallback=True,
        maxmind_license_key="",
        maxmind_account_id="",
        maxmind_update_interval_hours=0,
        log_level="warning",
    )


@pytest.fixture
def store(settings):
    return DatabaseStore(settings)


@pytest.fixture
def client(settings, store):
    app = create_app(settings=settings, store=store, start_background=False)
    with TestClient(app) as test_client:
        yield test_client
