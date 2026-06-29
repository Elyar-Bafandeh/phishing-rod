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
    # model binaries need not be duplicated into the service tree.
    ml_models_dir: str = "models"

    # --- Two-model weighted fusion -------------------------------------------
    # We run the URL model and the enhanced-HTML model independently and combine
    # their phishing probabilities as a weighted average. Weights are F1-derived
    # (URL F1 0.877, HTML-enhanced F1 0.967) and configurable so they can be
    # retuned without code changes. They are normalised at runtime, so they need
    # not sum to exactly 1.
    ml_weight_url: float = 0.475
    ml_weight_html: float = 0.525

    # Combined-probability thresholds that map a fused phishing probability to a
    # verdict: phishing >= phishing_threshold; suspicious in
    # [suspicious_threshold, phishing_threshold); otherwise safe.
    ml_phishing_threshold: float = 0.75
    ml_suspicious_threshold: float = 0.45

    # Minimum DOM length (characters, after trim) for the HTML model to be
    # trusted. Below this the capture is treated as empty/unusable and we fall
    # back to a URL-only verdict so a failed page render can't dominate.
    ml_dom_min_chars: int = 200


@lru_cache
def get_settings() -> Settings:
    """Cached settings instance (read once per process)."""
    return Settings()
