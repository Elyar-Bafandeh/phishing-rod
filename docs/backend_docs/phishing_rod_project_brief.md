# Phishing Rod Backend & ML Service Project Brief

**Project name:** Phishing Rod  
**Project type:** Laravel API backend + Python machine-learning prediction service  
**Primary goal:** AI-based phishing and malicious website detection using URL features and website HTML/content features  
**Current development phase:** Backend API foundation and database setup  
**Prepared for:** Human developers and AI coding assistants that need a complete understanding of the project before continuing implementation

---

## 1. Project Summary

Phishing Rod is a web application backend designed to accept a URL from a user, safely collect information about that URL, extract machine-learning features, and return a phishing/malicious-site confidence score.

The first version of the application is intentionally simple: it does **not** require user accounts or logins. Users submit URLs through a public API, similar in spirit to tools like VirusTotal or urlscan.io. Account support, scan history per user, dashboards, and authenticated API keys can be added later.

The core detection approach is hybrid:

1. **URL-based analysis**  
   The system extracts lexical, syntactic, structural, and domain-related features directly from the submitted URL.

2. **HTML/content-based analysis**  
   The system extracts static features from the website HTML/DOM, such as forms, password inputs, scripts, iframes, links, suspicious keywords, external assets, login-form indicators, and phishing-kit artifacts.

3. **Model-based prediction**  
   Extracted features are passed into trained machine-learning models stored in a Python service. The model returns a label and confidence score, for example `safe`, `suspicious`, or `phishing`.

The Laravel application should not directly execute machine-learning code. Laravel should orchestrate the scan lifecycle, database records, urlscan.io API calls, queues, and JSON API responses. Python should own feature extraction and model inference.

---

## 2. High-Level Architecture

```text
User / frontend / Postman
        |
        v
Laravel API backend
        |
        | 1. Validate submitted URL
        | 2. Create scan record in PostgreSQL
        | 3. Submit URL to urlscan.io
        | 4. Fetch urlscan.io result JSON and DOM/HTML
        | 5. Send URL + HTML + optional scan metadata to Python service
        | 6. Store prediction result
        v
PostgreSQL database

Laravel API backend
        |
        v
Python ML service
        |
        | 1. Extract URL features
        | 2. Extract HTML features
        | 3. Match exact feature schema used during training
        | 4. Load correct .joblib model
        | 5. Return prediction probabilities
        v
Laravel API backend returns result to client
```

The backend should be treated as an API-first application. Blade views are not the focus for the MVP. The current direction is to use `routes/api.php`, API controllers, JSON resources, request validation classes, and Postman testing.

---

## 3. Current Laravel Backend Status

The Laravel app has already been initialized and connected to PostgreSQL.

### Current project name

```text
phishing-rod
```

### Current database choice

```text
PostgreSQL
```

The database is managed through pgAdmin. The Laravel `.env` file should use a PostgreSQL connection similar to:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=phishing_rod
DB_USERNAME=postgres
DB_PASSWORD=your_password_here
```

### Current main app table

A first `scans` table has been created through a Laravel migration.

Initial table fields are expected to include:

```text
id
uuid
submitted_url
normalized_url
domain
status
verdict
confidence
error_message
completed_at
created_at
updated_at
```

The public API should expose the scan `uuid`, not the internal database `id`.

---

## 4. Current API Direction

The backend should focus on `routes/api.php`, not `routes/web.php`.

Current or expected routes:

```php
Route::post('/scans', [ScanController::class, 'store']);
Route::get('/scans/{uuid}', [ScanController::class, 'show']);
Route::get('/scans', [ScanController::class, 'index']);
```

Because these routes live in `routes/api.php`, they are accessed with the `/api` prefix:

```text
POST http://127.0.0.1:8000/api/scans
GET  http://127.0.0.1:8000/api/scans/{uuid}
GET  http://127.0.0.1:8000/api/scans
```

### Example request

```http
POST /api/scans
Content-Type: application/json
Accept: application/json
```

```json
{
  "url": "https://example.com"
}
```

### Example response

```json
{
  "data": {
    "uuid": "generated-scan-uuid",
    "submitted_url": "https://example.com",
    "normalized_url": "https://example.com",
    "domain": "example.com",
    "status": "queued",
    "verdict": null,
    "confidence": null,
    "error_message": null,
    "completed_at": null,
    "created_at": "...",
    "updated_at": "..."
  },
  "message": "Scan created successfully."
}
```

---

## 5. Recommended Laravel Backend Structure

A clean backend structure should look like this:

```text
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── ScanController.php
│   ├── Requests/
│   │   └── Api/
│   │       └── StoreScanRequest.php
│   └── Resources/
│       └── ScanResource.php
│
├── Models/
│   └── Scan.php
│
├── Jobs/
│   ├── SubmitUrlscanJob.php
│   ├── FetchUrlscanResultJob.php
│   ├── FetchUrlscanDomJob.php
│   └── RunPredictionJob.php
│
├── Services/
│   ├── Urlscan/
│   │   └── UrlscanClient.php
│   ├── Ml/
│   │   └── MlPredictionClient.php
│   └── Security/
│       └── UrlValidatorService.php
│
└── Enums/
    └── ScanStatus.php
```

For the current stage, only the controller, request, resource, model, and scan table are necessary. Jobs and services should be added gradually.

---

## 6. Scan Lifecycle

The final backend flow should be asynchronous. The user should not wait inside the original HTTP request while urlscan.io runs or while the model predicts.

Recommended scan statuses:

```text
queued
submitted_to_urlscan
waiting_for_urlscan
urlscan_complete
dom_fetched
predicting
completed
failed
```

### Detailed flow

1. User sends `POST /api/scans` with a URL.
2. Laravel validates the URL.
3. Laravel creates a `scans` row with `status = queued`.
4. Laravel dispatches a queue job.
5. Job submits the URL to urlscan.io.
6. Job stores urlscan.io scan ID and result URL.
7. Job waits or retries until urlscan.io result is available.
8. Job fetches result JSON.
9. Job fetches DOM/HTML.
10. Laravel sends URL + DOM/HTML + optional urlscan result JSON to Python ML service.
11. Python service extracts features and predicts.
12. Laravel stores prediction in PostgreSQL.
13. User polls `GET /api/scans/{uuid}` to see status and final result.

---

## 7. urlscan.io Integration

The project should use urlscan.io to safely collect website information instead of the Laravel backend directly visiting potentially malicious websites.

The urlscan.io API will be used for:

1. Submitting URLs for scanning.
2. Retrieving scan result JSON.
3. Retrieving DOM/HTML data.

The backend should store the urlscan.io API key in `.env`:

```env
URLSCAN_API_KEY=your_urlscan_api_key_here
URLSCAN_VISIBILITY=unlisted
URLSCAN_BASE_URL=https://urlscan.io
```

Use the `api-key` HTTP header when calling urlscan.io.

Recommended visibility for submitted scans:

```text
unlisted
```

This is safer than public scans because submitted URLs may contain sensitive query parameters or identifiers.

### Expected urlscan.io-related Laravel service

```text
app/Services/Urlscan/UrlscanClient.php
```

Possible methods:

```php
submitUrl(string $url): array
getResult(string $scanId): array
getDom(string $scanId): string
```

### Data stored from urlscan.io

At minimum:

```text
urlscan_scan_id
urlscan_result_json
urlscan_dom_html
final_url
http_status
scan_created_at
```

The DOM/HTML should not necessarily be stored directly in the `scans` table. A cleaner design is to store it as an artifact:

```text
storage/app/scans/{scan_uuid}/result.json
storage/app/scans/{scan_uuid}/dom.html
```

or in a separate database table such as `scan_artifacts`.

---

## 8. Python ML Service Overview

The Python ML service should be a separate internal service. Laravel should call it over HTTP.

Recommended technology:

```text
FastAPI
```

Recommended local development URL:

```text
http://127.0.0.1:9000
```

Recommended endpoint:

```text
POST /predict
```

Laravel should call this endpoint only from the backend. Users should not access the Python service directly.

---

## 9. Recommended Python Service Directory Structure

```text
ml-service/
├── app/
│   ├── main.py
│   ├── predictor.py
│   ├── schemas.py
│   │
│   ├── feature_extraction/
│   │   ├── url_features.py
│   │   └── html_features_enhanced.py
│   │
│   └── model_loader.py
│
├── models/
│   ├── best_combined_model.joblib
│   ├── best_html_enhanced_model.joblib
│   └── best_url_model.joblib
│
├── requirements.txt
└── .env
```

The model files should live inside the Python service, not inside the Laravel app and not inside the PostgreSQL database. The models already exist in and can be copied from the Trained_models folder within the project root.

---

## 10. Model Files That Must Be Included

The Python service should contain exactly **three** trained model files:

```text
best_combined_model.joblib
best_html_enhanced_model.joblib
best_url_model.joblib
```

There is no separate production/runtime role for `best_html_model.joblib`. That file represents the weaker initial HTML model and has been superseded by `best_html_enhanced_model.joblib`. The backend and Python service should not load or expose the initial/basic HTML model unless it is kept only for offline research comparison outside the application runtime.

Based on the final three model files, their intended roles are:

| Model file                        | Expected role                                                                                     |
| --------------------------------- | ------------------------------------------------------------------------------------------------- |
| `best_url_model.joblib`           | Uses only URL-based features extracted from the submitted URL.                                    |
| `best_html_enhanced_model.joblib` | Uses enhanced HTML/content features from the enhanced HTML extractor. This replaces the initial/basic HTML model. |
| `best_combined_model.joblib`      | Uses combined URL + enhanced HTML/content features and should likely be the main production/default model. |

The exact model selected at runtime should be configurable, for example:

```env
ACTIVE_MODEL=best_combined_model.joblib
```

Laravel does not need to know how these models work internally. Laravel only needs to know which model version produced the prediction.

---

## 11. Critical Feature Schema Requirement

This is one of the most important parts of the project.

The prediction-time feature extraction must match the training-time feature extraction exactly.

That means:

1. The same feature names must exist.
2. The same feature order must be used.
3. The same missing-value defaults must be used.
4. The same preprocessing, scaling, encoding, or vectorization must be applied.
5. The model must receive the exact format it was trained on.

A common failure mode is this:

```text
Model was trained with columns:
[url_length, hostname_length, path_length, form_count, password_input_count]

But prediction sends:
[form_count, password_input_count, url_length, hostname_length, path_length]
```

Even though the same values exist, the order is wrong, so the model output becomes unreliable.

Recommended solution:

```text
models/
├── best_combined_model.joblib
├── best_html_enhanced_model.joblib
├── best_url_model.joblib
├── combined_feature_schema.json
├── url_feature_schema.json
├── html_enhanced_feature_schema.json
└── metadata.json
```

A feature schema file should define the exact feature order expected by each model.

Example:

```json
{
  "model": "best_combined_model.joblib",
  "features": [
    "url_length",
    "hostname_length",
    "path_length",
    "query_length",
    "has_https",
    "form_count",
    "password_input_count",
    "external_link_count",
    "has_login_form"
  ]
}
```

---

## 12. URL Feature Extraction Code

The Python service should include URL feature extraction code matching the attached file:

```text
url_features.py
```

This file contains an `extract_url_features(url: str) -> dict` function.

Important characteristics of the URL feature extractor:

- Uses `urlparse` and `parse_qs` from Python standard library.
- Uses `tldextract` to split the host into subdomain, domain, suffix, and registered domain.
- Has a `safe_parse()` function that assumes `http://` if the scheme is missing.
- Detects IPv4-style hosts and hexadecimal IP indicators.
- Calculates Shannon entropy for URL and host randomness-like signals.
- Counts suspicious words such as `login`, `verify`, `secure`, `account`, `password`, `bank`, `payment`, `paypal`, `security`, `recover`, and similar terms.
- Detects common shortener domains such as `bit.ly`, `tinyurl.com`, `t.co`, `is.gd`, `cutt.ly`, and others.

### URL features produced include

```text
url_length
hostname_length
path_length
query_length
fragment_length
count_dots
count_hyphens
count_underscores
count_slashes
count_questionmarks
count_equals
count_amps
count_at
count_percent
num_digits
num_letters
has_https
has_ip_host
has_port
has_query
has_fragment
subdomain_labels
path_depth
num_params
total_param_values
domain
suffix
registered_domain
suspicious_word_count
is_shortener_domain
url_entropy
host_entropy
```

The Python service should not replace this extractor with a different one unless the model is retrained or feature schema is updated.

---

## 13. Enhanced HTML Feature Extraction Code

The Python service should include HTML/content feature extraction code matching the attached file:

```text
HtmlFeatureExtract_enhanced.py
```

The important runtime function is:

```python
extract_html_features(html: str, url: str) -> dict
```

This extractor is static and safe. It parses HTML as text/markup using BeautifulSoup. It does not render the page, execute JavaScript, or perform external requests.

Important characteristics:

- Uses BeautifulSoup with `html.parser`.
- Reads/parses HTML safely as text.
- Has `safe_read_html()` with a maximum byte size limit.
- Extracts structural HTML features.
- Extracts link, form, script, iframe, input, image, metadata, and title features.
- Counts suspicious keywords and brand keywords.
- Detects login-form indicators.
- Detects external form actions.
- Detects HTTP form actions.
- Detects JavaScript indicators like `fetch`, `XMLHttpRequest`, `FormData`, and submit event listeners.
- Detects external scripts, images, and CSS.
- Detects possible Telegram or Discord webhook artifacts.
- Detects suspicious PHP endpoint patterns.
- Detects base/canonical/meta-refresh domain mismatches.
- Counts CSRF/SSO-like hidden inputs.
- Detects possible phishing-kit comments and fake validation text.

### HTML features produced include

```text
html_bytes
html_char_count
text_char_count
text_word_count
html_entropy
tag_count
unique_tag_count
script_count
noscript_count
iframe_count
form_count
input_count
password_input_count
hidden_input_count
button_count
anchor_count
img_count
meta_count
link_tag_count
style_tag_count
title_present
title_length
has_favicon
has_meta_refresh
has_onclick
has_onload
has_eval
has_escape
has_unescape
has_window_open
mailto_count
tel_count
javascript_href_count
empty_href_count
internal_link_count
external_link_count
null_link_count
relative_link_count
https_link_count
malformed_url_count
suspicious_keyword_count
brand_keyword_count
has_login_form
copyright_symbol_count
comment_count
redirect_keyword_count
form_action_external_count
form_action_empty_count
form_action_http_count
external_script_count
has_fetch
has_xmlhttprequest
has_formdata
has_submit_listener
external_img_count
external_css_count
hotlinked_asset_count
has_telegram_webhook
has_discord_webhook
suspicious_php_endpoint_count
has_base_domain_mismatch
has_canonical_domain_mismatch
has_meta_refresh_domain_mismatch
csrf_token_count
nonce_attribute_count
sso_parameter_count
missing_auth_token_in_login_form
phishing_kit_comment_count
has_fake_validation_text
```

The enhanced HTML extractor should be used for the model file:

```text
best_html_enhanced_model.joblib
```

and also for:

```text
best_combined_model.joblib
```

if the combined model was trained using URL + enhanced HTML features. The older/basic HTML extractor and `best_html_model.joblib` should not be used by the runtime application.

---

## 14. Python `/predict` API Contract

The Laravel backend should send a request like this to the Python service:

```http
POST http://127.0.0.1:9000/predict
Content-Type: application/json
Authorization: Bearer internal-service-token
```

```json
{
  "url": "https://example.com",
  "dom_html": "<html>...</html>",
  "urlscan_result": {
    "task": {},
    "page": {},
    "lists": {},
    "data": {},
    "stats": {},
    "verdicts": {}
  },
  "model_name": "best_combined_model.joblib"
}
```

The Python service should respond:

```json
{
  "label": "safe",
  "confidence": 0.87,
  "safe_probability": 0.87,
  "phishing_probability": 0.13,
  "model_name": "best_combined_model.joblib",
  "feature_schema_version": "combined-v1",
  "features": {
    "url_length": 19,
    "hostname_length": 11,
    "form_count": 0,
    "password_input_count": 0
  }
}
```

Laravel should convert probabilities into display-friendly percentages only at the API/output layer:

```text
0.87 -> 87%
```

---

## 15. Python Service Example Implementation Concept

Example `main.py`:

```python
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel
from typing import Any

from app.predictor import Predictor

app = FastAPI()
predictor = Predictor()

class PredictRequest(BaseModel):
    url: str
    dom_html: str | None = None
    urlscan_result: dict[str, Any] | None = None
    model_name: str | None = None

@app.post("/predict")
def predict(request: PredictRequest, authorization: str | None = Header(default=None)):
    # Validate internal token here.
    return predictor.predict(
        url=request.url,
        dom_html=request.dom_html,
        urlscan_result=request.urlscan_result,
        model_name=request.model_name,
    )
```

Example `predictor.py` responsibilities:

```text
1. Load the selected .joblib model.
2. Extract URL features using url_features.py.
3. Extract HTML features using HtmlFeatureExtract_enhanced.py.
4. Merge features if using combined model.
5. Reorder features according to feature_schema.json.
6. Apply preprocessing if needed.
7. Call model.predict_proba().
8. Return label, confidence, probabilities, model name, and feature dictionary.
```

---

## 16. Model Storage Rules

The `.joblib` models should be stored on disk inside the Python service:

```text
ml-service/models/
```

Do not store the model binaries in PostgreSQL.

The database may store model metadata only:

```text
model name
model version
feature schema version
accuracy
precision
recall
F1-score
ROC-AUC
created date
active/inactive flag
```

A future `model_versions` table could be useful.

---

## 17. Laravel Database Expansion Plan

The first version has only a simple `scans` table. Later, the schema should be expanded.

### `scans`

Main scan record.

```text
id
uuid
submitted_url
normalized_url
domain
status
verdict
confidence
error_message
completed_at
created_at
updated_at
```

### `urlscan_submissions`

Stores urlscan.io-specific metadata.

```text
id
scan_id
urlscan_scan_id
urlscan_result_url
urlscan_visibility
submitted_at
result_fetched_at
dom_fetched_at
raw_submission_response
created_at
updated_at
```

### `scan_artifacts`

Stores paths to downloaded artifacts.

```text
id
scan_id
type
storage_path
external_url
sha256
size_bytes
content_type
created_at
updated_at
```

Artifact examples:

```text
result_json
dom_html
screenshot
```

### `feature_sets`

Stores extracted feature dictionaries returned by Python.

```text
id
scan_id
feature_schema_version
url_features
html_features
combined_features
created_at
updated_at
```

### `predictions`

Stores model output.

```text
id
scan_id
model_name
model_version
label
confidence
safe_probability
phishing_probability
raw_probabilities
created_at
updated_at
```

---

## 18. Security and Abuse Prevention

Because the first version does not require login, public API abuse prevention is important.

The Laravel backend should eventually add:

1. Rate limiting by IP.
2. URL validation.
3. Blocking private/internal addresses.
4. Blocking unsupported schemes such as `file://`, `ftp://`, `javascript:`, and `data:`.
5. Maximum URL length.
6. Maximum stored DOM size.
7. Scan visibility set to `unlisted` on urlscan.io.
8. Internal-only Python service access.
9. API token between Laravel and Python.
10. No direct public exposure of Python `/predict` endpoint.

Laravel should not directly browse submitted URLs. urlscan.io should be the safe retrieval layer.

---

## 19. Important Design Decisions

### Laravel should not load `.joblib` models

Laravel is responsible for the API, database, queues, and orchestration. Python is responsible for ML.

### Python should not own the scan lifecycle

Python should not create Laravel database rows or manage user-facing scan status. It should only receive data and return predictions.

### urlscan.io should fetch HTML/DOM

The backend should not directly visit potentially malicious websites. urlscan.io should provide DOM/HTML and scan metadata.

### Feature extraction must remain consistent

The feature extraction code in the Python service must match the training code used for the `.joblib` models.

### The combined model should likely be default

For production-like behavior, `best_combined_model.joblib` should probably be the active default because it can use both URL and HTML/content features.

---

## 20. Near-Term Development Roadmap

### Step 1: Finish API cleanup

- `StoreScanRequest`
- `ScanResource`
- API-only controller
- Postman testing

### Step 2: Add a basic queue job

Create a job that changes scan status from:

```text
queued -> processing -> completed
```

This tests queue infrastructure before adding urlscan.io.

### Step 3: Add urlscan.io service

Create:

```text
app/Services/Urlscan/UrlscanClient.php
```

Add methods:

```text
submitUrl()
getResult()
getDom()
```

### Step 4: Add Python service skeleton

Create `ml-service/` with FastAPI and a mock `/predict` endpoint.

### Step 5: Add real feature extraction

Move/adapt:

```text
url_features.py
HtmlFeatureExtract_enhanced.py
```

into:

```text
ml-service/app/feature_extraction/
```

### Step 6: Load `.joblib` models

Place model files into:

```text
ml-service/models/
```

Load exactly these three runtime models:

```text
best_combined_model.joblib
best_html_enhanced_model.joblib
best_url_model.joblib
```

Do not load `best_html_model.joblib` in the application runtime. The enhanced HTML model replaces it.

### Step 7: Connect Laravel to Python

Create:

```text
app/Services/Ml/MlPredictionClient.php
```

Laravel sends URL + DOM HTML to Python and stores the response.

### Step 8: Store final prediction

Update `scans` table or create `predictions` table.

Final API response should include:

```text
status
verdict
confidence
model_name
created_at
completed_at
```

---

## 21. Final Expected User Experience

A client sends:

```json
{
  "url": "https://suspicious-example.com/login"
}
```

The backend returns immediately:

```json
{
  "data": {
    "uuid": "scan-uuid",
    "status": "queued",
    "submitted_url": "https://suspicious-example.com/login"
  }
}
```

The client then polls:

```text
GET /api/scans/{uuid}
```

When complete, the response looks like:

```json
{
  "data": {
    "uuid": "scan-uuid",
    "submitted_url": "https://suspicious-example.com/login",
    "domain": "suspicious-example.com",
    "status": "completed",
    "verdict": "phishing",
    "confidence": 94.25,
    "model_name": "best_combined_model.joblib",
    "completed_at": "..."
  }
}
```

The result should be worded as a probability/confidence estimate, not an absolute truth.

Example:

```text
The model estimates this URL as phishing with 94.25% confidence.
```

---

## 22. Summary for Future AI Coding Tools

This project is a Laravel API backend called **Phishing Rod**. It uses PostgreSQL and exposes public scan endpoints with no login for the MVP. Users submit a URL. Laravel stores a scan record and will later dispatch jobs to submit the URL to urlscan.io. urlscan.io is responsible for safely retrieving scan metadata and DOM/HTML. Laravel then sends the submitted URL, DOM/HTML, and possibly urlscan result JSON to a separate internal Python FastAPI service.

The Python service owns all machine-learning logic. It must include the same feature extraction logic as the provided `url_features.py` and `HtmlFeatureExtract_enhanced.py` files. It must load exactly three runtime `.joblib` model files: `best_combined_model.joblib`, `best_html_enhanced_model.joblib`, and `best_url_model.joblib`. The older `best_html_model.joblib` is not part of the runtime application because the enhanced HTML model replaces it. Prediction-time feature extraction must match training-time feature order and preprocessing exactly. The Python service returns a label, confidence score, probabilities, model name, and extracted features. Laravel stores and exposes this result through the API.

The project should be built gradually: first clean API and database, then queues, then urlscan.io integration, then Python mock service, then real feature extraction and model inference.
