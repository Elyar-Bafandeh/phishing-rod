"""Phishing Rod ML service — FastAPI app.

Internal-only inference service. `/predict` runs the two-model weighted fusion
(URL model + enhanced-HTML model) and returns a phishing verdict. Every request
to `/predict` must carry the shared bearer token.
"""

from fastapi import Depends, FastAPI, HTTPException, status

from . import predictor
from .model_loader import ModelError
from .schemas import PredictRequest, PredictResponse
from .security import require_token

app = FastAPI(title="Phishing Rod ML Service", version="1.0.0")


@app.get("/health")
def health() -> dict[str, str]:
    """Unauthenticated liveness probe."""
    return {"status": "ok"}


@app.post("/predict", response_model=PredictResponse)
def predict(payload: PredictRequest, _: None = Depends(require_token)) -> PredictResponse:
    try:
        result = predictor.predict(url=payload.url, dom_html=payload.dom_html)
    except ModelError as exc:
        # Misconfiguration (missing/forbidden model file) — not the caller's fault.
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail=f"Model unavailable: {exc}",
        ) from exc

    return PredictResponse(**result)
