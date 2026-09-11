from geolookupservice.config import Settings
from geolookupservice.maxmind_store import DatabaseStore
from tests.fakes import FakeCityReader


def test_reload_closes_previous_reader_after_grace(tmp_path, monkeypatch):
    city_path = tmp_path / "GeoLite2-City.mmdb"
    city_path.write_bytes(b"one")
    created: list[FakeDiskReader] = []

    class FakeDiskReader:
        def __init__(self, path: str) -> None:
            self.path = path
            self.closed = False
            created.append(self)

        def close(self) -> None:
            self.closed = True

    monkeypatch.setattr("geolookupservice.maxmind_store.Reader", FakeDiskReader)
    settings = Settings(
        maxmind_data_dir=str(tmp_path),
        maxmind_reload_grace_seconds=0,
        maxmind_allow_fallback=True,
        maxmind_license_key="",
    )
    store = DatabaseStore(settings)
    assert store.load() is True
    assert len(created) == 1
    first = created[0]

    city_path.write_bytes(b"two-bytes")
    assert store.reload_if_changed() is True
    assert len(created) == 2
    assert first.closed is True
    assert store.readers()[0] is created[1]
    assert created[1].closed is False
    assert store.reload_count >= 2


def test_reload_skips_when_unchanged(tmp_path, monkeypatch):
    city_path = tmp_path / "GeoLite2-City.mmdb"
    city_path.write_bytes(b"same")
    opened = {"n": 0}

    class FakeDiskReader:
        def __init__(self, path: str) -> None:
            opened["n"] += 1
            self.path = path

        def close(self) -> None:
            pass

    monkeypatch.setattr("geolookupservice.maxmind_store.Reader", FakeDiskReader)
    settings = Settings(maxmind_data_dir=str(tmp_path), maxmind_license_key="")
    store = DatabaseStore(settings)
    store.load()
    assert opened["n"] == 1
    assert store.reload_if_changed() is False
    assert opened["n"] == 1


def test_invalid_mmdb_is_not_retried_until_it_changes(tmp_path, monkeypatch):
    city_path = tmp_path / "GeoLite2-City.mmdb"
    city_path.write_bytes(b"not-a-database")
    opened = {"n": 0}

    class BoomReader:
        def __init__(self, path: str) -> None:
            opened["n"] += 1
            raise OSError("invalid database")

        def close(self) -> None:
            pass

    monkeypatch.setattr("geolookupservice.maxmind_store.Reader", BoomReader)
    settings = Settings(maxmind_data_dir=str(tmp_path), maxmind_license_key="")
    store = DatabaseStore(settings)
    assert store.load() is False
    assert opened["n"] == 1
    assert store.reload_if_changed() is False
    assert opened["n"] == 1
    assert store.mode == "fallback"


def test_override_readers_marks_maxmind_mode():
    settings = Settings(maxmind_data_dir="/tmp/unused-geo", maxmind_license_key="")
    store = DatabaseStore(settings)
    assert store.mode == "fallback"
    store.override_readers_for_tests(FakeCityReader(), None)
    assert store.mode == "maxmind"
    assert store.ready is True
