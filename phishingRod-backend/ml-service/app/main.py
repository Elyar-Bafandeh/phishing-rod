"""Phishing Rod ML service — FastAPI skeleton (Phase 9).

This is the internal-only inference service. For now `/predict` returns a
MOCK result: it validates the request, enforces the model allowlist and the
bearer token, but performs no real feature extraction or model inference.
Real loading/extraction/prediction arrives in Phase 10.
"""

from fastapi import Depends, FastAPI, HTTPException, status

from .config import get_settings
from .schemas import PredictRequest, PredictResponse
from .security import require_token

# The only model names that may ever be requested at runtime. The deprecated
# `best_html_model.joblib` is intentionally excluded and must never be served.
ALLOWED_MODELS = frozenset(
    {
        "best_combined_model.joblib",
        "best_html_enhanced_model.joblib",
        "best_url_model.joblib",
    }
)

app = FastAPI(title="Phishing Rod ML Service", version="0.1.0")


@app.get("/health")
def health() -> dict[str, str]:
    """Unauthenticated liveness probe."""
    return {"status": "ok"}


@app.post("/predict", response_model=PredictResponse)
def predict(payload: PredictRequest, _: None = Depends(require_token)) -> PredictResponse:
    settings = get_settings()
    model_name = payload.model_name or settings.ml_active_model

    if model_name not in ALLOWED_MODELS:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=f"Unknown or disallowed model: {model_name}",
        )

    # MOCK response — proves the contract and auth path end-to-end. Phase 10
    # replaces this with real feature extraction + .joblib inference.
    return PredictResponse(
        label="safe",
        confidence=0.5,
        safe_probability=0.5,
        phishing_probability=0.5,
        model_name="mock",
        feature_schema_version="mock-v1",
        features={},
    )
