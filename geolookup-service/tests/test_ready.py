from fastapi.testclient import TestClient

from geolookupservice.app import create_app
from geolookupservice.config import Settings
from geolookupservice.maxmind_store import DatabaseStore


def test_ready_503_when_fallback_disabled_and_no_mmdb(tmp_path):
    settings = Settings(
        maxmind_data_dir=str(tmp_path),
        maxmind_allow_fallback=False,
        maxmind_license_key="",
        maxmind_update_interval_hours=0,
    )
    store = DatabaseStore(settings)
    app = create_app(settings=settings, store=store, start_background=False)
    with TestClient(app) as client:
        response = client.get("/ready")
        assert response.status_code == 503
        assert response.json()["status"] == "not_ready"
        assert client.get("/health").status_code == 200
