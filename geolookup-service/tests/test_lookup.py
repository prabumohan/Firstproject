from geolookupservice.lookup import classify_ip, lookup_ip
from geolookupservice.maxmind_store import DatabaseStore
from geolookupservice.config import Settings
from tests.fakes import FakeAsnReader, FakeCityReader, city_model


def test_classify_public_and_private():
    _, kind, is_private, version = classify_ip("1.1.1.1")
    assert kind == "public" and is_private is False and version == 4
    _, kind, is_private, version = classify_ip("::1")
    assert kind == "loopback" and is_private is True and version == 6


def test_lookup_combines_city_and_asn():
    store = DatabaseStore(Settings(maxmind_data_dir="/tmp/unused", maxmind_license_key=""))
    store.override_readers_for_tests(
        FakeCityReader({"8.8.8.8": city_model(city="Mountain View", country_code="US", country="United States")}),
        FakeAsnReader(),
    )
    result = lookup_ip(store, "8.8.8.8")
    assert result.source == "maxmind"
    assert result.location is not None
    assert result.location.city == "Mountain View"
    assert result.asn == 15169
    assert result.org == "Google LLC"
