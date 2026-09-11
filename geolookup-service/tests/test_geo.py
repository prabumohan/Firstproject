from geolookupservice.geo import bearing_degrees, haversine_km, km_to_miles, longitude_offset_hours


def test_haversine_london_paris():
    km = haversine_km(51.5074, -0.1278, 48.8566, 2.3522)
    assert 340 < km < 350


def test_km_to_miles():
    assert abs(km_to_miles(1.0) - 0.621371) < 1e-9


def test_bearing_north():
    bearing = bearing_degrees(0.0, 0.0, 1.0, 0.0)
    assert bearing == 0.0 or bearing > 359


def test_longitude_offset():
    assert longitude_offset_hours(0) == 0
    assert longitude_offset_hours(75) == 5
    assert longitude_offset_hours(-180) == -12
