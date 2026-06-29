"""API-layer tests for the ML service.

The predictor is stubbed so these stay fast and independent of the real models.
"""

from fastapi.testclient import TestClient

from app import predictor
from app.config import get_settings
from app.main import app

client = TestClient(app)

TOKEN = get_settings().ml_service_token
AUTH = {"Authorization": f"Bearer {TOKEN}"}


def _fake_result():
    return {
        "verdict": "safe",
        "confidence": 0.9,
        "combined_phishing_probability": 0.1,
        "url_only_fallback": False,
        "weights": {"url": 0.475, "html": 0.525},
        "url": {
            "model_name": "best_url_model.joblib",
            "schema_version": "url-v1",
            "label": "safe",
            "phishing_probability": 0.12,
            "safe_probability": 0.88,
        },
        "html": {
            "model_name": "best_html_enhanced_model.joblib",
            "schema_version": "html-enhanced-v1",
            "label": "safe",
            "phishing_probability": 0.08,
            "safe_probability": 0.92,
        },
        "features": {"url": {"url_length": 19}, "html": {"form_count": 0}},
    }


def test_health_is_ok_and_unauthenticated():
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json() == {"status": "ok"}


def test_predict_requires_bearer_token():
    response = client.post("/predict", json={"url": "https://example.com"})
    assert response.status_code == 401


def test_predict_rejects_wrong_token():
    response = client.post(
        "/predict",
        json={"url": "https://example.com"},
        headers={"Authorization": "Bearer wrong-token"},
    )
    assert response.status_code == 401


def test_predict_requires_url():
    response = client.post("/predict", json={}, headers=AUTH)
    assert response.status_code == 422


def test_predict_returns_fusion_shape(monkeypatch):
    monkeypatch.setattr(predictor, "predict", lambda url, dom_html=None: _fake_result())

    response = client.post(
        "/predict",
        json={"url": "https://example.com", "dom_html": "<html></html>"},
        headers=AUTH,
    )
    assert response.status_code == 200

    body = response.json()
    assert body["verdict"] == "safe"
    assert body["url"]["model_name"] == "best_url_model.joblib"
    assert body["html"]["model_name"] == "best_html_enhanced_model.joblib"
    assert body["url_only_fallback"] is False
    assert set(body["weights"]) == {"url", "html"}


def test_predict_allows_null_html_block_on_fallback(monkeypatch):
    result = _fake_result()
    result["html"] = None
    result["url_only_fallback"] = True
    result["weights"] = {"url": 1.0, "html": 0.0}
    monkeypatch.setattr(predictor, "predict", lambda url, dom_html=None: result)

    response = client.post("/predict", json={"url": "https://example.com"}, headers=AUTH)
    assert response.status_code == 200
    assert response.json()["html"] is None
    assert response.json()["url_only_fallback"] is True
