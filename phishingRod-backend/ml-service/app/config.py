"""Runtime configuration for the Phishing Rod ML service.

Values come from environment variables (or a local .env file) so secrets such
as the internal bearer token stay out of the codebase. Field names map to
upper-cased env vars: `ml_service_token` -> `ML_SERVICE_TOKEN`, etc.
"""

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=False,
        extra="ignore",
    )

    # Shared secret the Laravel backend sends as `Authorization: Bearer <token>`.
    ml_service_token: str = "dev-internal-token-change-me"

    # Directory holding the runtime .joblib models. Configurable so the large
    # model binaries need not be duplicated into the service tree — Phase 10
    # can point this at the existing models folder. Defaults to ./models.
    ml_models_dir: str = "models"

    # Model used when a request does not specify one.
    ml_active_model: str = "best_combined_model.joblib"


@lru_cache
def get_settings() -> Settings:
    """Cached settings instance (read once per process)."""
    return Settings()
