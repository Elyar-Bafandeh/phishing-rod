# Phishing Rod — Backend

The complete, runnable part of the **Phishing Rod** thesis project: a hybrid
ML pipeline that detects phishing / malicious URLs. A user submits a URL to the
API; the system retrieves the page safely through
[urlscan.io](https://urlscan.io) (it never browses the URL itself), extracts
URL + HTML features, runs two ML models, and returns a verdict — `safe`,
`suspicious`, or `phishing` — with a confidence score.

> **No frontend exists yet**, so you test the system through its REST API. This
> README covers everything needed to run and test the backend. (The repository's
> root README is nearly identical, since the backend is all that's runnable today.)

## What's in this folder

```
phishingRod-backend/
├── phishing-rod/   # Laravel 12 REST API: orchestration, DB, queue-job chain
└── ml-service/     # Internal FastAPI ML service: feature extraction + inference
```

- **`phishing-rod/`** dispatches a chain of database-backed queue jobs that call
  urlscan.io, fetch the rendered DOM, call the ML service's `/predict`, and store
  the verdict.
- **`ml-service/`** runs two scikit-learn models (URL + enhanced-HTML) and fuses
  their scores. It is bearer-token protected and must never be exposed publicly.
  See [`ml-service/README.md`](ml-service/README.md) for service-specific detail.

## Architecture

```
Client → Laravel API (port 8000) → PostgreSQL
                │  (database-backed queue jobs)
                ▼
         urlscan.io API  ──►  Python FastAPI ML service (port 9000, internal only)
```

---

## ⚠️ Before you start: the ML model files are NOT in the repo

The trained models are large (~665 MB total) and **git-ignored**, so a fresh
clone will not contain them. At runtime you need two:

| File | Needed for |
|------|------------|
| `best_url_model.joblib` | always |
| `best_html_enhanced_model.joblib` | the HTML model (used when a DOM is available) |

Obtain these two files (from the thesis author / training project) and place them
in **`ml-service/models/`**. Without them the ML service can't run and its
real-model test fails. (`best_combined_model.joblib` and `best_html_model.joblib`
are intentionally not used.)

> You do **not** need the models, PostgreSQL, or any API key to run the **Laravel
> automated test suite** (Level 1). They're only needed for the ML service
> (Level 2 + the ML test suite).

---

## Prerequisites

| Tool | Version used | Needed for |
|------|--------------|------------|
| PHP | 8.2+ | Laravel API |
| Composer | 2.x | PHP dependencies |
| Python | 3.11+ | ML service |
| PostgreSQL | 14+ | real runs only (tests use in-memory SQLite) |
| Node.js | 20+ | optional — only if you build front-end assets |
| urlscan.io API key | — | real end-to-end runs only ([free signup](https://urlscan.io/user/signup)) |

---

## Level 1 — Run the automated test suites (fastest, no accounts needed)

The Laravel suite uses in-memory SQLite and **fakes every external service**
(urlscan.io, the ML service, the queue, the filesystem) — so it needs **no
PostgreSQL, no API key, and no models**.

### Backend (Laravel) — 96 tests

```bash
cd phishing-rod
composer install
cp .env.example .env          # Windows PowerShell: copy .env.example .env
php artisan key:generate
composer test                 # or: php artisan test
```

### ML service (Python) — 14 tests

Most tests use stub models, but one **integration test loads the real `.joblib`
files**, so place the two model files in `ml-service/models/` first.

```bash
cd ml-service
python -m venv venv
venv\Scripts\activate         # *nix: source venv/bin/activate
pip install -r requirements-dev.txt
pytest
```

---

## Level 2 — Run the full pipeline end-to-end (real scan)

Needs PostgreSQL, the model files, and a live urlscan.io API key.

### 1. Configure the Laravel app

```bash
cd phishing-rod
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set at least:

```env
DB_DATABASE=phishing_rod        # create this PostgreSQL database first
DB_USERNAME=postgres
DB_PASSWORD=your_pg_password

URLSCAN_API_KEY=your_live_urlscan_key   # required for real scans
ML_SERVICE_URL=http://127.0.0.1:9000
ML_SERVICE_TOKEN=dev-internal-token-change-me   # must match the ML service token
```

Then create the schema:

```bash
php artisan migrate
```

### 2. Configure the ML service

```bash
cd ../ml-service
python -m venv venv
venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env            # set ML_SERVICE_TOKEN to match Laravel's value
# place best_url_model.joblib + best_html_enhanced_model.joblib in ./models/
```

### 3. Start the three processes (three terminals)

```bash
# Terminal 1 — ML service
cd ml-service && venv\Scripts\activate
uvicorn app.main:app --host 127.0.0.1 --port 9000 --reload

# Terminal 2 — Laravel API
cd phishing-rod && php artisan serve

# Terminal 3 — queue worker (this drives the job chain)
cd phishing-rod && php artisan queue:work
```

### 4. Submit a scan and poll for the result

```bash
curl -X POST http://127.0.0.1:8000/api/scans \
  -H "Content-Type: application/json" \
  -d '{"url":"https://example.com"}'

curl http://127.0.0.1:8000/api/scans/<uuid>
```

> **Windows PowerShell note:** `curl` is an alias for `Invoke-WebRequest`. Use
> `Invoke-RestMethod` instead:
> ```powershell
> $body = @{ url = "https://example.com" } | ConvertTo-Json
> $scan = Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8000/api/scans -ContentType "application/json" -Body $body
> Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/scans/$($scan.data.uuid)" | ConvertTo-Json -Depth 6
> ```

The `status` field walks:
`queued → submitted_to_urlscan → waiting_for_urlscan → urlscan_complete → dom_fetched → predicting → completed`.
A full scan typically takes ~20–60 s, dominated by urlscan.io's real scan time.
On any failure the scan ends `failed` with a high-level `error_message`.

---

## API endpoints

All routes prefixed with `/api/`. No authentication (public MVP).

| Method | Route | Description |
|--------|-------|-------------|
| `POST` | `/api/scans` | Submit a URL — body `{ "url": "https://..." }` |
| `GET`  | `/api/scans/{uuid}` | Poll a scan's status / verdict / per-model breakdown |
| `GET`  | `/api/scans` | List the 20 most recent scans |

## Useful Laravel commands (from `phishing-rod/`)

```bash
php artisan test                 # full suite (in-memory SQLite)
php artisan test --filter=Name   # targeted
php artisan migrate              # apply migrations to PostgreSQL
php artisan route:list           # list routes
php artisan queue:work           # process queued jobs
```

---

## Verdict thresholds

The two models' phishing probabilities are fused
(`p = 0.475·url + 0.525·html`, URL-only fallback when the DOM is too small) and
mapped to a verdict: **phishing ≥ 0.75**, **suspicious 0.45–0.75**, **safe < 0.45**.

## Troubleshooting

- **Scan stays `queued`** → the queue worker isn't running.
- **Fails at `predicting`** → the ML service is unreachable or `ML_SERVICE_TOKEN`
  doesn't match between the two `.env` files.
- **ML service won't start / `503` on `/predict`** → model files aren't in
  `ml-service/models/`.
- **Fails early with an auth/rate message** → check `URLSCAN_API_KEY`.
- **`composer test` errors about APP_KEY** → run `php artisan key:generate`.
