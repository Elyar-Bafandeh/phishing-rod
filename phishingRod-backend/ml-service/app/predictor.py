"""Two-model weighted phishing predictor.

Runs the URL model and the enhanced-HTML model independently, then fuses their
phishing probabilities with a configurable weighted average:

    p_combined = w_url * p_url + w_html * p_html      (weights normalised)

When the DOM is missing or too small to trust, the HTML model is skipped and the
verdict falls back to URL-only so a failed page capture cannot dominate. The
combined probability is mapped to a verdict via configurable thresholds.

The deprecated combined model is intentionally not used here.
"""

from typing import Any, Optional

import pandas as pd

from .config import get_settings
from .feature_extraction.html_features_enhanced import extract_html_features
from .feature_extraction.url_features import extract_url_features
from .model_loader import HTML_MODEL, URL_MODEL, load_model

URL_SCHEMA_VERSION = "url-v1"
HTML_SCHEMA_VERSION = "html-enhanced-v1"

# Per-model decision threshold for the individual (stored) labels. No tuned
# thresholds were persisted at training time, so 0.5 is used per model. The
# user-facing verdict uses the combined thresholds instead.
SINGLE_MODEL_THRESHOLD = 0.5


def _phishing_probability(model: Any, features: dict) -> float:
    """Run a pipeline and return P(phishing) using the model's own column order."""
    X = pd.DataFrame([features]).reindex(columns=model.feature_names_in_, fill_value=0)
    proba = model.predict_proba(X)[0]
    classes = list(model.classes_)
    # Class 1 == phishing (confirmed from training); fall back to last column.
    index = classes.index(1) if 1 in classes else len(classes) - 1
    return float(proba[index])


def _model_score(model_name: str, schema_version: str, phishing_probability: float) -> dict:
    return {
        "model_name": model_name,
        "schema_version": schema_version,
        "label": "phishing" if phishing_probability >= SINGLE_MODEL_THRESHOLD else "safe",
        "phishing_probability": phishing_probability,
        "safe_probability": 1.0 - phishing_probability,
    }


def _verdict(p_combined: float, settings) -> str:
    if p_combined >= settings.ml_phishing_threshold:
        return "phishing"
    if p_combined >= settings.ml_suspicious_threshold:
        return "suspicious"
    return "safe"


def predict(url: str, dom_html: Optional[str] = None) -> dict:
    """Produce a fused phishing verdict for a URL (+ optional DOM)."""
    settings = get_settings()

    # --- URL model (always runs) ---
    url_features = extract_url_features(url)
    p_url = _phishing_probability(load_model(URL_MODEL), url_features)
    url_score = _model_score(URL_MODEL, URL_SCHEMA_VERSION, p_url)

    # --- HTML model (only when the DOM is usable) ---
    dom = (dom_html or "").strip()
    html_usable = len(dom) >= settings.ml_dom_min_chars

    html_features: Optional[dict] = None
    html_score: Optional[dict] = None
    p_html: Optional[float] = None

    if html_usable:
        html_features = extract_html_features(dom_html, url)
        p_html = _phishing_probability(load_model(HTML_MODEL), html_features)
        html_score = _model_score(HTML_MODEL, HTML_SCHEMA_VERSION, p_html)

    # --- Weighted fusion (normalised), with URL-only fallback ---
    if p_html is None:
        applied = {"url": 1.0, "html": 0.0}
        p_combined = p_url
        url_only_fallback = True
    else:
        total = settings.ml_weight_url + settings.ml_weight_html
        applied = {
            "url": settings.ml_weight_url / total,
            "html": settings.ml_weight_html / total,
        }
        p_combined = applied["url"] * p_url + applied["html"] * p_html
        url_only_fallback = False

    verdict = _verdict(p_combined, settings)
    # Confidence = probability mass behind the chosen verdict.
    confidence = p_combined if verdict in ("phishing", "suspicious") else 1.0 - p_combined

    return {
        "verdict": verdict,
        "confidence": confidence,
        "combined_phishing_probability": p_combined,
        "url_only_fallback": url_only_fallback,
        "weights": applied,
        "url": url_score,
        "html": html_score,
        "features": {"url": url_features, "html": html_features},
    }
