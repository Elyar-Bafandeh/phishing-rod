# Phishing Rod — ML Service

Internal FastAPI service that turns a URL + captured DOM into a phishing
verdict. **Internal-only** — it is never exposed publicly and every request to
`/predict` must carry the shared bearer token (`ML_SERVICE_TOKEN`).

> **Status: Phase 9 skeleton.** `/predict` returns a *mock* response — it
> validates the request, the model allowlist, and the token, but does no real
> feature extraction or inference yet. Phase 10 wires the real models.

## Layout

```
ml-service/
├── app/
│   ├── main.py                     # FastAPI app: /health, /predict (mock)
│   ├── config.py                   # env-backed settings (token, models dir, default model)
│   ├── security.py                 # bearer-token dependency
│   ├── schemas.py                  # PredictRequest / PredictResponse
│   └── feature_extraction/         # extractors (present, wired in Phase 10)
│       ├── url_features.py         # extract_url_features(url) -> dict
│       └── html_features_enhanced.py  # extract_html_features(html, url) -> dict
├── models/                         # runtime .joblib models (git-ignored, see below)
├── requirements.txt                # runtime deps (+ commented Phase 10 deps)
├── requirements-dev.txt            # + pytest/httpx
└── .env(.example)
```

## Runtime models

Only these three model names are allowed at runtime (the deprecated
`best_html_model.joblib` must **never** be loaded or served):

- `best_combined_model.joblib` (default)
- `best_html_enhanced_model.joblib`
- `best_url_model.joblib`

The binaries are large (~650 MB total) and are **not** committed here. Point
`ML_MODELS_DIR` at wherever they live (e.g. the repo's
`Trained_models_and_python_files/`) or copy the three files into `models/`.
Phase 10 uses this setting to load them.

## Run locally

```bash
cd ml-service
python -m venv venv
venv\Scripts\activate            # Windows  (use: source venv/bin/activate on *nix)
pip install -r requirements.txt
cp .env.example .env             # then edit the token if needed
uvicorn app.main:app --host 127.0.0.1 --port 9000 --reload
```

## Endpoints

### `GET /health`
Unauthenticated liveness probe → `{"status": "ok"}`.

### `POST /predict`
Requires `Authorization: Bearer <ML_SERVICE_TOKEN>`.

Request:
```json
{ "url": "https://example.com", "dom_html": "<html>...</html>", "model_name": "best_combined_model.joblib" }
```

Mock response (Phase 9):
```json
{
  "label": "safe",
  "confidence": 0.5,
  "safe_probability": 0.5,
  "phishing_probability": 0.5,
  "model_name": "mock",
  "feature_schema_version": "mock-v1",
  "features": {}
}
```

A missing/invalid token returns `401`; an unknown or disallowed `model_name`
returns `422`.

## Tests

```bash
pip install -r requirements-dev.txt
pytest
```
