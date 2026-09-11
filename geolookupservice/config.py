"""Environment-driven configuration. Never put real MaxMind keys in source."""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path

from pydantic import Field
from pydantic_settings import BaseSettings, PydanticBaseSettingsSource, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=False,
    )

    @classmethod
    def settings_customise_sources(
        cls,
        settings_cls: type[BaseSettings],
        init_settings: PydanticBaseSettingsSource,
        env_settings: PydanticBaseSettingsSource,
        dotenv_settings: PydanticBaseSettingsSource,
        file_secret_settings: PydanticBaseSettingsSource,
    ) -> tuple[PydanticBaseSettingsSource, ...]:
        # Explicit constructor args win so tests can pin a temp data dir.
        return env_settings, dotenv_settings, file_secret_settings, init_settings

    host: str = "0.0.0.0"
    port: int = 8080
    log_level: str = "info"
    log_format: str = "text"  # text | json

    maxmind_license_key: str = Field(default="", repr=False)
    maxmind_account_id: str = ""
    maxmind_data_dir: str = "/data/maxmind"
    maxmind_editions: str = "GeoLite2-City,GeoLite2-ASN"
    maxmind_update_interval_hours: float = 24.0
    maxmind_allow_fallback: bool = True
    maxmind_download_timeout_seconds: float = 120.0
    maxmind_reload_grace_seconds: float = 30.0
    maxmind_watch_interval_seconds: float = 15.0

    geoip_city_db_path: str = ""
    geoip_asn_db_path: str = ""

    cors_origins: str = "*"
    batch_max_addresses: int = 50

    @property
    def data_dir(self) -> Path:
        return Path(self.maxmind_data_dir)

    @property
    def editions(self) -> list[str]:
        return [item.strip() for item in self.maxmind_editions.split(",") if item.strip()]

    @property
    def update_interval_seconds(self) -> float:
        return max(0.0, float(self.maxmind_update_interval_hours) * 3600.0)

    @property
    def cors_origin_list(self) -> list[str]:
        if self.cors_origins.strip() == "*":
            return ["*"]
        return [item.strip() for item in self.cors_origins.split(",") if item.strip()]

    @property
    def has_license(self) -> bool:
        return bool(self.maxmind_license_key.strip())


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()


def reset_settings_cache() -> None:
    get_settings.cache_clear()
