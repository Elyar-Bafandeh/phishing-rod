# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Phishing Rod** is a thesis web application that detects phishing/malicious URLs using hybrid ML analysis. Users submit a URL via API; the system returns a verdict (`safe`, `suspicious`, `phishing`) with a confidence score.

**Current status**: Laravel API foundation is complete (CRUD endpoints working). Queue jobs, urlscan.io integration, and the Python ML service are not yet implemented.

## Architecture

```
Client → Laravel API (port 8000) → PostgreSQL
                ↓ (queue jobs — not yet built)
         urlscan.io API  ←→  Python FastAPI ML Service (port 9000)
```

- **Laravel** (`phishingRod-backend/phishing-rod/`): Orchestration, REST API, database, queue jobs
- **Python ML service** (`Trained_models_and_python_files/` → future `ml-service/`): Feature extraction + model inference using scikit-learn `.joblib` models
- **urlscan.io**: Fetches HTML/DOM safely (the app never browses URLs directly)
- **Frontend** (`phishingRod-frontend/`): Not started yet — planned Vue 3 + Vite + Tailwind

The Laravel backend dispatches queue jobs (`QUEUE_CONNECTION=database`) that call urlscan.io, receive the DOM, call the Python `/predict` endpoint, then store results back in the `scans` table.

## Backend Commands

All commands run from `phishingRod-backend/phishing-rod/`.

```bash
# First-time setup
composer install && npm install
cp .env.example .env
php artisan key:generate
php artisan migrate

# Start everything in parallel (API + queue worker + logs + Vite)
composer dev

# Individual services
php artisan serve          # API on http://127.0.0.1:8000
php artisan queue:work     # Process queue jobs
php artisan pail           # Stream logs

# Run tests
composer test

# Useful artisan commands
php artisan route:list
php artisan migrate:status
php artisan tinker
```

## Key Configuration (`.env`)

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=phishing_rod

QUEUE_CONNECTION=database
CACHE_STORE=database

# Not yet integrated — future phases
URLSCAN_API_KEY=
URLSCAN_VISIBILITY=unlisted
ML_SERVICE_URL=http://127.0.0.1:9000
ML_SERVICE_TOKEN=
```

## API Endpoints

All routes in `routes/api.php`, prefix `/api/`.

| Method | Route | Notes |
|--------|-------|-------|
| POST | `/api/scans` | Submit URL; body: `{ "url": "https://..." }` |
| GET | `/api/scans/{uuid}` | Poll scan status/results |
| GET | `/api/scans` | List recent scans (limit 20) |

No authentication for MVP — public/anonymous API.

## Database Schema (`scans` table)

| Column | Type | Notes |
|--------|------|-------|
| uuid | uuid | Public identifier |
| submitted_url / normalized_url | text | Raw and cleaned URL |
| domain | string (indexed) | Extracted domain |
| status | string (indexed) | `queued` → `processing` → `completed` / `failed` |
| verdict | string nullable | `safe`, `suspicious`, `phishing` |
| confidence | decimal(5,2) nullable | 0.00–100.00% |
| completed_at | timestamp nullable | |

## ML Models

Three active `.joblib` models (do not use the deprecated `best_html_model.joblib`):

| File | Input | Use |
|------|-------|-----|
| `best_combined_model.joblib` | URL + HTML features | **Primary/default** |
| `best_html_enhanced_model.joblib` | HTML features only | Content-focused |
| `best_url_model.joblib` | URL features only | Fast/lightweight |

Feature extraction is handled by two Python modules:
- `url_features.py` → `extract_url_features(url)` → 39 features
- `HtmlFeatureExtract_enhanced.py` → `extract_html_features(html, url)` → 62 features

**Critical**: Feature extraction order/preprocessing must exactly match training time. Any change requires retraining all models.

## Python `/predict` Contract

```json
// Request
{ "url": "...", "dom_html": "<html>...", "model_name": "best_combined_model.joblib" }

// Response
{ "label": "safe", "confidence": 0.87, "safe_probability": 0.87, "phishing_probability": 0.13 }
```

## Implementation Roadmap

See `docs/backend_docs/phishing_rod_backend_phased_plan.md` for full detail.

- **Done**: Scan model, migrations, CRUD API endpoints
- **Next (Phase 2)**: `ScanResource`, `ScanStatus` enum, URL normalization service
- **Phase 3**: Queue job infrastructure (`ProcessScanJob`)
- **Phase 4**: urlscan.io client (`SubmitUrlscanJob`, `FetchDomJob`)
- **Phase 5–6**: Python FastAPI service with real feature extraction and model loading
- **Phase 7**: Laravel ↔ Python integration (`MlPredictionClient`)

## Security Rules

- Never browse submitted URLs directly — always use urlscan.io as the retrieval layer
- The Python ML service must not be publicly accessible (internal only)
- HTML is parsed as static text only — no JavaScript execution
- Only the three named `.joblib` models may be loaded at runtime; no user-supplied models
