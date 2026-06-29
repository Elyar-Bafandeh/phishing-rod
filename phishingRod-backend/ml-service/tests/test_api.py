"""Skeleton API tests for the ML service (Phase 9).

These verify the contract and auth path — not real inference (that's Phase 10).
"""

from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import app

client = TestClient(app)

TOKEN = get_settings().ml_service_token
AUTH = {"Authorization": f"Bearer {TOKEN}"}


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


def test_predict_returns_mock_shape_with_valid_token():
    response = client.post(
        "/predict",
        json={"url": "https://example.com", "dom_html": "<html></html>"},
        headers=AUTH,
    )
    assert response.status_code == 200

    body = response.json()
    for key in (
        "label",
        "confidence",
        "safe_probability",
        "phishing_probability",
        "model_name",
        "feature_schema_version",
        "features",
    ):
        assert key in body

    assert body["model_name"] == "mock"
    assert body["feature_schema_version"] == "mock-v1"


def test_predict_accepts_allowed_model_name():
    response = client.post(
        "/predict",
        json={"url": "https://example.com", "model_name": "best_url_model.joblib"},
        headers=AUTH,
    )
    assert response.status_code == 200


def test_predict_rejects_deprecated_model():
    response = client.post(
        "/predict",
        json={"url": "https://example.com", "model_name": "best_html_model.joblib"},
        headers=AUTH,
    )
    assert response.status_code == 422


def test_predict_rejects_unknown_model():
    response = client.post(
        "/predict",
        json={"url": "https://example.com", "model_name": "evil_model.joblib"},
        headers=AUTH,
    )
    assert response.status_code == 422


def test_predict_requires_url():
    response = client.post("/predict", json={}, headers=AUTH)
    assert response.status_code == 422
