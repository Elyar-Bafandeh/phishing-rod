"""Fusion / fallback / threshold logic tests.

Models and feature extractors are stubbed so the weighting math is verified
deterministically without loading the real ~500 MB models.
"""

import pytest

from app import predictor
from app.model_loader import HTML_MODEL, URL_MODEL


class FakeModel:
    """Minimal stand-in for an sklearn pipeline: returns a fixed P(phishing)."""

    def __init__(self, phishing_prob: float):
        self.feature_names_in_ = ["f0", "f1"]
        self.classes_ = [0, 1]
        self._p = phishing_prob

    def predict_proba(self, X):
        return [[1.0 - self._p, self._p]]


@pytest.fixture
def stub(monkeypatch):
    """Stub extractors (return empty dicts) and let tests set per-model probs."""
    monkeypatch.setattr(predictor, "extract_url_features", lambda url: {"f0": 1})
    monkeypatch.setattr(predictor, "extract_html_features", lambda html, url: {"f1": 1})

    probs = {URL_MODEL: 0.0, HTML_MODEL: 0.0}
    monkeypatch.setattr(predictor, "load_model", lambda name: FakeModel(probs[name]))
    return probs


def test_weighted_fusion_math(stub):
    stub[URL_MODEL] = 0.2
    stub[HTML_MODEL] = 0.9

    result = predictor.predict("https://example.com", dom_html="x" * 500)

    # 0.475*0.2 + 0.525*0.9 = 0.5675
    assert result["combined_phishing_probability"] == pytest.approx(0.5675, abs=1e-6)
    assert result["url_only_fallback"] is False
    assert result["verdict"] == "suspicious"  # 0.45 <= 0.5675 < 0.75
    assert result["html"] is not None
    assert result["url"]["phishing_probability"] == pytest.approx(0.2)
    assert result["html"]["phishing_probability"] == pytest.approx(0.9)


def test_thin_dom_falls_back_to_url_only(stub):
    stub[URL_MODEL] = 0.9
    stub[HTML_MODEL] = 0.0  # would drag the score down if it were used

    result = predictor.predict("https://example.com", dom_html="<html></html>")  # < 200 chars

    assert result["url_only_fallback"] is True
    assert result["html"] is None
    assert result["weights"] == {"url": 1.0, "html": 0.0}
    assert result["combined_phishing_probability"] == pytest.approx(0.9)
    assert result["verdict"] == "phishing"


def test_missing_dom_falls_back_to_url_only(stub):
    stub[URL_MODEL] = 0.1
    result = predictor.predict("https://example.com", dom_html=None)

    assert result["url_only_fallback"] is True
    assert result["verdict"] == "safe"


def test_high_combined_probability_is_phishing(stub):
    stub[URL_MODEL] = 0.95
    stub[HTML_MODEL] = 0.95
    result = predictor.predict("https://example.com", dom_html="x" * 500)
    assert result["verdict"] == "phishing"
    assert result["confidence"] == pytest.approx(result["combined_phishing_probability"])


def test_low_combined_probability_is_safe_with_inverted_confidence(stub):
    stub[URL_MODEL] = 0.05
    stub[HTML_MODEL] = 0.05
    result = predictor.predict("https://example.com", dom_html="x" * 500)
    assert result["verdict"] == "safe"
    # Confidence for a safe verdict is the mass behind "safe".
    assert result["confidence"] == pytest.approx(1.0 - result["combined_phishing_probability"])


def test_per_model_label_uses_half_threshold(stub):
    stub[URL_MODEL] = 0.6
    stub[HTML_MODEL] = 0.4
    result = predictor.predict("https://example.com", dom_html="x" * 500)
    assert result["url"]["label"] == "phishing"
    assert result["html"]["label"] == "safe"
