"""Request/response models for the ML service API."""

from typing import Any, Optional

from pydantic import BaseModel, ConfigDict, Field


class PredictRequest(BaseModel):
    url: str = Field(..., min_length=1)
    dom_html: Optional[str] = None
    # Accepted for forward-compatibility but ignored: the service always runs
    # the two-model weighted fusion rather than a single selectable model.
    urlscan_result: Optional[dict[str, Any]] = None


class ModelScore(BaseModel):
    # `model_name` is in pydantic's protected `model_` namespace; opt out.
    model_config = ConfigDict(protected_namespaces=())

    model_name: str
    schema_version: str
    label: str
    phishing_probability: float
    safe_probability: float


class PredictResponse(BaseModel):
    model_config = ConfigDict(protected_namespaces=())

    # Final fused verdict.
    verdict: str
    confidence: float
    combined_phishing_probability: float

    # Fusion detail.
    url_only_fallback: bool
    weights: dict[str, float]

    # Per-model results. `html` is null when the DOM was missing/too small.
    url: ModelScore
    html: Optional[ModelScore] = None

    # Raw extracted features for storage (keys: "url", "html"; "html" may be null).
    features: dict[str, Any]
