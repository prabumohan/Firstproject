from tests.fakes import FakeAsnReader, FakeCityReader, city_model


def test_index_ui(client):
    response = client.get("/")
    assert response.status_code == 200
    assert "GeoLookupService" in response.text
    assert "/api/v1/ip" in response.text
    response = client.get("/health")
    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["service"] == "geolookupservice"


def test_ready_fallback(client):
    response = client.get("/ready")
    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ready"
    assert body["mode"] == "fallback"


def test_status_reports_no_license(client):
    body = client.get("/api/v1/status").json()
    assert body["mode"] == "fallback"
    assert body["has_license_configured"] is False
    assert body["ready"] is True


def test_fallback_known_ip(client):
    response = client.get("/api/v1/ip", params={"address": "8.8.8.8"})
    assert response.status_code == 200
    body = response.json()
    assert body["found"] is True
    assert body["source"] == "fallback"
    assert body["location"]["city"] == "Mountain View"
    assert body["asn"] == 15169
    assert "fallback" in (body["message"] or "").lower()


def test_fallback_unknown_public_ip(client):
    body = client.get("/api/v1/ip", params={"address": "203.0.113.10"}).json()
    assert body["found"] is False
    assert body["source"] == "fallback"
    assert body["network_type"] == "public"


def test_private_ip(client):
    body = client.get("/api/v1/ip", params={"address": "192.168.1.10"}).json()
    assert body["is_private"] is True
    assert body["found"] is False
    assert body["network_type"] == "private"


def test_invalid_ip(client):
    response = client.get("/api/v1/ip", params={"address": "not-an-ip"})
    assert response.status_code == 400


def test_client_ip_header(client):
    body = client.get("/api/v1/ip", headers={"X-Forwarded-For": "8.8.4.4"}).json()
    assert body["ip"] == "8.8.4.4"
    assert body["found"] is True


def test_batch_lookup(client):
    response = client.post("/api/v1/ip/batch", json={"addresses": ["8.8.8.8", "127.0.0.1"]})
    assert response.status_code == 200
    body = response.json()
    assert body["count"] == 2
    assert body["results"][0]["found"] is True
    assert body["results"][1]["is_private"] is True


def test_batch_rejects_invalid(client):
    response = client.post("/api/v1/ip/batch", json={"addresses": ["8.8.8.8", "nope"]})
    assert response.status_code == 400


def test_city_search(client):
    body = client.get("/api/v1/search", params={"q": "london"}).json()
    assert body["count"] >= 1
    assert body["results"][0]["name"] == "London"


def test_city_alias(client):
    body = client.get("/api/v1/search", params={"q": "nyc"}).json()
    assert body["results"][0]["id"] == "new-york"


def test_get_city(client):
    body = client.get("/api/v1/cities/tokyo").json()
    assert body["country_code"] == "JP"


def test_list_cities_country_filter(client):
    body = client.get("/api/v1/cities", params={"country": "IN", "limit": 10}).json()
    assert body["total"] >= 1
    assert all(city["country_code"] == "IN" for city in body["cities"])


def test_reverse_near_paris(client):
    body = client.get("/api/v1/reverse", params={"lat": 48.8566, "lon": 2.3522}).json()
    assert body["nearest"]["id"] == "paris"
    assert body["distance_km"] < 1


def test_distance_cities(client):
    body = client.get("/api/v1/distance", params={"origin": "london", "destination": "paris"}).json()
    assert 340 < body["distance_km"] < 350


def test_timezone_from_city(client):
    body = client.get("/api/v1/timezone", params={"lat": 35.6762, "lon": 139.6503}).json()
    assert body["timezone"] == "Asia/Tokyo"
    assert body["source"] == "city"


def test_maxmind_lookup_overrides_fallback(client, store):
    store.override_readers_for_tests(
        FakeCityReader({"8.8.8.8": city_model(city="Council Bluffs", country="United States", country_code="US")}),
        FakeAsnReader(),
    )
    body = client.get("/api/v1/ip", params={"address": "8.8.8.8"}).json()
    assert body["source"] == "maxmind"
    assert body["location"]["city"] == "Council Bluffs"
    assert body["asn"] == 15169
    assert client.get("/ready").json()["mode"] == "maxmind"


def test_maxmind_unknown_address(client, store):
    store.override_readers_for_tests(FakeCityReader({}), FakeAsnReader({}))
    body = client.get("/api/v1/ip", params={"address": "203.0.113.99"}).json()
    assert body["source"] == "maxmind"
    assert body["found"] is False
