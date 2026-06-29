# Phishing Rod — ML Service

Internal FastAPI service that turns a URL + captured DOM into a phishing
verdict. **Internal-only** — it is never exposed publicly and every request to
`/predict` must carry the shared bearer token (`ML_SERVICE_TOKEN`).

> **Status: real inference (two-model weighted fusion).** `/predict` runs the
> URL model and the enhanced-HTML model and fuses their phishing probabilities
> (`p = 0.475·url + 0.525·html`, URL-only fallback when the DOM is thin). The
> combined model is not used. See
> `../../docs/backend_docs/phishing_rod_ml_models_reference.md`.

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

Only **two** models are loaded at runtime (the loader rejects everything else):

- `best_url_model.joblib` — 29 URL features
- `best_html_enhanced_model.joblib` — 69 enhanced-HTML features

`best_combined_model.joblib` is **not used** (trained on the deprecated HTML
feature set) and `best_html_model.joblib` is **deprecated** — both are rejected.

The binaries are large and **not** committed here. Point `ML_MODELS_DIR` at
wherever they live, or copy the files into `models/` (already done locally).

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

Request (no `model_name` — both models always run):
```json
{ "url": "https://example.com", "dom_html": "<html>...</html>" }
```

Response (fused verdict + both per-model scores):
```json
{
  "verdict": "safe",
  "confidence": 0.86,
  "combined_phishing_probability": 0.14,
  "url_only_fallback": false,
  "weights": { "url": 0.475, "html": 0.525 },
  "url":  { "model_name": "best_url_model.joblib", "schema_version": "url-v1", "label": "safe", "phishing_probability": 0.18, "safe_probability": 0.82 },
  "html": { "model_name": "best_html_enhanced_model.joblib", "schema_version": "html-enhanced-v1", "label": "safe", "phishing_probability": 0.10, "safe_probability": 0.90 },
  "features": { "url": {}, "html": {} }
}
```

A missing/invalid token returns `401`. When the DOM is missing/too small, `html`
is `null`, `url_only_fallback` is `true`, and `weights` is `{url:1, html:0}`.

## Tests

```bash
pip install -r requirements-dev.txt
pytest
```
