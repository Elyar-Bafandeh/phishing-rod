"""Request/response models for the ML service API."""

from typing import Any, Optional

from pydantic import BaseModel, ConfigDict, Field


class PredictRequest(BaseModel):
    # `model_name` lives in pydantic's protected `model_` namespace; opt out so
    # the field name is allowed without warnings.
    model_config = ConfigDict(protected_namespaces=())

    url: str = Field(..., min_length=1)
    dom_html: Optional[str] = None
    urlscan_result: Optional[dict[str, Any]] = None
    model_name: Optional[str] = None


class PredictResponse(BaseModel):
    model_config = ConfigDict(protected_namespaces=())

    label: str
    confidence: float
    safe_probability: float
    phishing_probability: float
    model_name: str
    feature_schema_version: str
    features: dict[str, Any]
