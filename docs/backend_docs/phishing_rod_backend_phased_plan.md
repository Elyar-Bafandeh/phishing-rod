# Phishing Rod Backend Implementation Plan

**Document purpose:** This Markdown file complements `phishing_rod_project_brief.md`. The project brief explains the whole system and ML concept. This file explains **what the Laravel backend will become** and gives a **phase-by-phase implementation plan** detailed enough for a human developer or an AI coding agent such as Claude Code to execute safely.

**Project name:** Phishing Rod  
**Backend framework:** Laravel API backend  
**Database:** PostgreSQL  
**Frontend status:** Not part of this plan yet  
**Authentication status:** No login for MVP  
**External scan provider:** urlscan.io API  
**ML runtime:** Separate internal Python/FastAPI service  

---

## 1. Backend Goal

The backend will expose a public API where a client submits a URL and receives a scan record. The scan will be processed asynchronously. Laravel will validate and store the URL, call urlscan.io to safely retrieve HTML/DOM and scan metadata, send that information to the internal Python ML service, store the prediction, and expose the final result through a JSON API.

The final backend flow should be:

```text
Client / Postman / future frontend
        |
        v
POST /api/scans
        |
        v
Laravel validates URL and creates scan row
        |
        v
Laravel queue jobs process scan asynchronously
        |
        +--> Submit URL to urlscan.io
        +--> Poll/fetch urlscan.io result JSON
        +--> Fetch DOM/HTML from urlscan.io
        +--> Store artifacts
        +--> Send URL + HTML + metadata to Python ML service
        +--> Store prediction
        |
        v
GET /api/scans/{uuid} returns status/result
```

Laravel is the orchestration layer. Python is the machine-learning layer. PostgreSQL is the persistent state layer.

---

## 2. Non-Negotiable Design Rules

These rules should be followed by any developer or AI coding tool working on this project.

1. **Do not build Blade pages for the MVP.** Use `routes/api.php`, API controllers, request classes, JSON resources, and Postman/cURL testing.
2. **Do not add authentication yet.** The MVP is anonymous and public, like a simple scanner.
3. **Do not make Laravel load `.joblib` files.** Laravel must not directly run scikit-learn/XGBoost models.
4. **Do not make Python manage Laravel database state.** Python should only receive data and return predictions.
5. **Do not directly browse submitted URLs from Laravel.** urlscan.io is the safe retrieval layer for DOM/HTML.
6. **Do not expose the Python ML service publicly.** It should be internal-only and protected with a shared token.
7. **Do not store large raw HTML in the `scans` table.** Store large artifacts in `storage/app/scans/{scan_uuid}/...` and save paths in the database.
8. **Do not break the feature schema.** Python prediction-time feature extraction must match the uploaded `url_features.py` and `HtmlFeatureExtract_enhanced.py` logic and the trained `.joblib` models.
9. **Do not return internal database IDs as the primary public identifier.** API clients should use scan `uuid`.
10. **Do not block the user while urlscan.io and ML inference run.** Use queue jobs.

---

## 3. Current Baseline Assumption

This plan assumes the following work has already been completed:

```text
Laravel app created: phishing-rod
Database connected: PostgreSQL
Database name: phishing_rod
API direction chosen: routes/api.php
Initial scans table created
Scan model created
POST /api/scans works in Postman
GET /api/scans/{uuid} works or is ready to work
GET /api/scans works or is ready to work
```

The current first table is expected to contain at least:

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

If any of this is not true, complete Phase 1 before moving forward.

---

# Phase 1 — Stabilize the API Foundation

## Goal

Make the current API clean, predictable, and safe to build on.

## Files involved

```text
routes/api.php
app/Models/Scan.php
app/Http/Controllers/Api/ScanController.php
app/Http/Requests/Api/StoreScanRequest.php
app/Http/Resources/ScanResource.php
database/migrations/*create_scans_table.php
```

## Tasks

### 1.1 Confirm API routes

Ensure `routes/api.php` contains:

```php
use App\Http\Controllers\Api\ScanController;
use Illuminate\Support\Facades\Route;

Route::post('/scans', [ScanController::class, 'store']);
Route::get('/scans', [ScanController::class, 'index']);
Route::get('/scans/{uuid}', [ScanController::class, 'show']);
```

Expected actual URLs:

```text
POST http://127.0.0.1:8000/api/scans
GET  http://127.0.0.1:8000/api/scans
GET  http://127.0.0.1:8000/api/scans/{uuid}
```

### 1.2 Confirm request validation

Create or confirm:

```text
app/Http/Requests/Api/StoreScanRequest.php
```

Required validation rules:

```php
return [
    'url' => [
        'required',
        'string',
        'url',
        'starts_with:http://,https://',
        'max:2048',
    ],
];
```

The `authorize()` method must return `true` because there is no login yet.

### 1.3 Confirm JSON resource

Create or confirm:

```text
app/Http/Resources/ScanResource.php
```

It should expose:

```text
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

It should not expose `id` as a necessary public identifier.

### 1.4 Confirm controller responsibilities

`ScanController` should:

- Use `StoreScanRequest` for validation.
- Normalize the URL minimally with `rtrim($submittedUrl, '/')` for now.
- Extract domain using `parse_url($normalizedUrl, PHP_URL_HOST)` for now.
- Create a `Scan` row with `status = queued`.
- Return `ScanResource` with HTTP `201` on creation.
- Return `ScanResource` for single scan lookup.
- Return `ScanResource::collection()` for list scans.

### 1.5 Confirm model fillable fields

`app/Models/Scan.php` must include:

```php
protected $fillable = [
    'uuid',
    'submitted_url',
    'normalized_url',
    'domain',
    'status',
    'verdict',
    'confidence',
    'error_message',
    'completed_at',
];
```

Add casts:

```php
protected $casts = [
    'confidence' => 'decimal:2',
    'completed_at' => 'datetime',
];
```

## Manual acceptance tests

Run Laravel:

```bash
php artisan serve
```

Create scan:

```http
POST http://127.0.0.1:8000/api/scans
Accept: application/json
Content-Type: application/json
```

Body:

```json
{
  "url": "https://example.com"
}
```

Expected:

```text
HTTP 201
JSON response contains data.uuid
JSON response contains status = queued
A row exists in PostgreSQL scans table
```

Test invalid URL:

```json
{
  "url": "not-a-url"
}
```

Expected:

```text
HTTP 422
JSON validation error
No scan row created
```

---

# Phase 2 — Introduce Backend Domain Structure

## Goal

Create a maintainable internal structure before adding urlscan.io and ML logic.

## Files to create

```text
app/Enums/ScanStatus.php
app/Actions/Scans/CreateScanAction.php
app/Actions/Scans/NormalizeUrlAction.php
app/Services/Security/UrlValidatorService.php
```

## Tasks

### 2.1 Create `ScanStatus` enum

Create:

```text
app/Enums/ScanStatus.php
```

Enum values:

```php
namespace App\Enums;

enum ScanStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case SubmittedToUrlscan = 'submitted_to_urlscan';
    case WaitingForUrlscan = 'waiting_for_urlscan';
    case UrlscanComplete = 'urlscan_complete';
    case DomFetched = 'dom_fetched';
    case Predicting = 'predicting';
    case Completed = 'completed';
    case Failed = 'failed';
}
```

Use the enum values when updating status instead of hard-coded strings where possible.

### 2.2 Add status cast on `Scan`

In `app/Models/Scan.php`, add:

```php
use App\Enums\ScanStatus;

protected $casts = [
    'status' => ScanStatus::class,
    'confidence' => 'decimal:2',
    'completed_at' => 'datetime',
];
```

Important: If this breaks JSON output because the resource returns the enum object, update `ScanResource` to output:

```php
'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
```

### 2.3 Create URL normalization action

Create:

```text
app/Actions/Scans/NormalizeUrlAction.php
```

Expected method:

```php
public function execute(string $url): array
```

Return shape:

```php
[
    'submitted_url' => $originalUrl,
    'normalized_url' => $normalizedUrl,
    'domain' => $domain,
]
```

For now, normalization should only:

- Trim whitespace.
- Remove trailing slash with `rtrim($url, '/')`.
- Lowercase only the host if safely implemented.
- Extract host/domain.

Do not over-normalize query parameters yet.

### 2.4 Create scan creation action

Create:

```text
app/Actions/Scans/CreateScanAction.php
```

Expected method:

```php
public function execute(string $url): Scan
```

Responsibilities:

1. Call `NormalizeUrlAction`.
2. Create `Scan` row.
3. Set `status = ScanStatus::Queued`.
4. Return the created `Scan` model.

### 2.5 Update controller to use action

`ScanController@store` should become thin:

```php
public function store(StoreScanRequest $request, CreateScanAction $createScanAction)
{
    $scan = $createScanAction->execute($request->validated('url'));

    return (new ScanResource($scan))
        ->additional(['message' => 'Scan created successfully.'])
        ->response()
        ->setStatusCode(201);
}
```

## Acceptance tests

Same as Phase 1. The API behavior must not change.

---

# Phase 3 — Add Database Tables for Full Backend State

## Goal

Prepare the database for urlscan.io artifacts, features, predictions, and model metadata.

## Important rule

Do not remove the existing `scans` table. Add new migrations.

## Commands

```bash
php artisan make:model UrlscanSubmission -m
php artisan make:model ScanArtifact -m
php artisan make:model FeatureSet -m
php artisan make:model Prediction -m
php artisan make:model ModelVersion -m
```

## Tables and columns

### 3.1 `urlscan_submissions`

Migration columns:

```php
$table->id();
$table->foreignId('scan_id')->constrained()->cascadeOnDelete();
$table->string('urlscan_scan_id')->nullable()->index();
$table->text('urlscan_result_url')->nullable();
$table->string('urlscan_visibility')->default('unlisted');
$table->timestamp('submitted_at')->nullable();
$table->timestamp('result_fetched_at')->nullable();
$table->timestamp('dom_fetched_at')->nullable();
$table->json('raw_submission_response')->nullable();
$table->text('error_message')->nullable();
$table->timestamps();
```

Relationships:

```php
// Scan.php
public function urlscanSubmission()
{
    return $this->hasOne(UrlscanSubmission::class);
}

// UrlscanSubmission.php
public function scan()
{
    return $this->belongsTo(Scan::class);
}
```

### 3.2 `scan_artifacts`

Migration columns:

```php
$table->id();
$table->foreignId('scan_id')->constrained()->cascadeOnDelete();
$table->string('type')->index(); // result_json, dom_html, screenshot, other
$table->text('storage_path')->nullable();
$table->text('external_url')->nullable();
$table->string('sha256')->nullable()->index();
$table->unsignedBigInteger('size_bytes')->nullable();
$table->string('content_type')->nullable();
$table->timestamps();
```

Relationships:

```php
// Scan.php
public function artifacts()
{
    return $this->hasMany(ScanArtifact::class);
}
```

### 3.3 `feature_sets`

Migration columns:

```php
$table->id();
$table->foreignId('scan_id')->constrained()->cascadeOnDelete();
$table->string('feature_schema_version')->nullable();
$table->json('url_features')->nullable();
$table->json('html_features')->nullable();
$table->json('combined_features')->nullable();
$table->timestamps();
```

Relationships:

```php
// Scan.php
public function featureSet()
{
    return $this->hasOne(FeatureSet::class);
}
```

### 3.4 `predictions`

Migration columns:

```php
$table->id();
$table->foreignId('scan_id')->constrained()->cascadeOnDelete();
$table->foreignId('model_version_id')->nullable()->constrained()->nullOnDelete();
$table->string('model_name')->nullable();
$table->string('model_version')->nullable();
$table->string('label')->nullable(); // safe, suspicious, phishing
$table->decimal('confidence', 5, 2)->nullable();
$table->decimal('safe_probability', 5, 2)->nullable();
$table->decimal('phishing_probability', 5, 2)->nullable();
$table->json('raw_probabilities')->nullable();
$table->json('explanation')->nullable();
$table->timestamps();
```

Relationships:

```php
// Scan.php
public function prediction()
{
    return $this->hasOne(Prediction::class);
}
```

### 3.5 `model_versions`

Migration columns:

```php
$table->id();
$table->string('name');
$table->string('version')->nullable();
$table->string('model_type')->nullable(); // xgboost, random_forest, neural_network, etc.
$table->string('feature_schema_version')->nullable();
$table->json('metrics')->nullable();
$table->boolean('is_active')->default(false);
$table->timestamps();
```

## Model fillable/casts

Each new model should have `fillable` fields matching the migration.

JSON columns should be cast as arrays:

```php
protected $casts = [
    'raw_submission_response' => 'array',
];
```

or for other models:

```php
protected $casts = [
    'url_features' => 'array',
    'html_features' => 'array',
    'combined_features' => 'array',
];
```

## Run migration

```bash
php artisan migrate
```

## Acceptance tests

```bash
php artisan migrate:status
```

Expected:

```text
All new migrations show as Ran
Tables visible in pgAdmin under phishing_rod -> Schemas -> public -> Tables
Existing /api/scans endpoints still work
```

---

# Phase 4 — Add Queue Infrastructure With a Mock Processor

## Goal

Prove that asynchronous scan processing works before integrating urlscan.io.

## Environment

Use database queue for local development first.

In `.env`:

```env
QUEUE_CONNECTION=database
```

If the `jobs` table does not exist, run:

```bash
php artisan queue:table
php artisan migrate
```

## Commands

```bash
php artisan make:job ProcessScanJob
```

## Job behavior for this phase only

`ProcessScanJob` should:

1. Accept `scanId` in constructor.
2. Load scan by ID.
3. Set status to `processing`.
4. Optionally sleep for 1 second to simulate work.
5. Set temporary mock result:
   - `status = completed`
   - `verdict = safe`
   - `confidence = 50.00`
   - `completed_at = now()`
6. Save scan.

This is not final ML behavior. It is only for proving queue flow.

## Update `CreateScanAction` or controller

After scan creation, dispatch:

```php
ProcessScanJob::dispatch($scan->id);
```

## Run worker

In a separate terminal:

```bash
php artisan queue:work
```

## Acceptance tests

1. Send `POST /api/scans`.
2. Response should immediately return `status = queued` or possibly `processing` depending on timing.
3. Run `GET /api/scans/{uuid}` after a few seconds.
4. Expected final result:

```json
{
  "status": "completed",
  "verdict": "safe",
  "confidence": "50.00"
}
```

5. PostgreSQL row should update.

## Phase completion rule

Do not proceed to urlscan.io until the queue worker successfully updates scans.

---

# Phase 5 — Add Configuration Files for External Services

## Goal

Centralize service configuration before writing clients.

## Files to create

```text
config/urlscan.php
config/ml.php
```

## `.env` values to add

```env
URLSCAN_BASE_URL=https://urlscan.io
URLSCAN_API_KEY=your_urlscan_api_key_here
URLSCAN_VISIBILITY=unlisted
URLSCAN_TIMEOUT=30

ML_SERVICE_URL=http://127.0.0.1:9000
ML_SERVICE_TOKEN=dev-internal-token-change-me
ML_SERVICE_TIMEOUT=30
ML_ACTIVE_MODEL=best_combined_model.joblib
```

## `config/urlscan.php`

Expected structure:

```php
return [
    'base_url' => env('URLSCAN_BASE_URL', 'https://urlscan.io'),
    'api_key' => env('URLSCAN_API_KEY'),
    'visibility' => env('URLSCAN_VISIBILITY', 'unlisted'),
    'timeout' => (int) env('URLSCAN_TIMEOUT', 30),
];
```

## `config/ml.php`

Expected structure:

```php
return [
    'base_url' => env('ML_SERVICE_URL', 'http://127.0.0.1:9000'),
    'token' => env('ML_SERVICE_TOKEN'),
    'timeout' => (int) env('ML_SERVICE_TIMEOUT', 30),
    'active_model' => env('ML_ACTIVE_MODEL', 'best_combined_model.joblib'),
];
```

## Command after editing config

```bash
php artisan config:clear
```

## Acceptance tests

Use Tinker:

```bash
php artisan tinker
```

```php
config('urlscan.base_url');
config('ml.base_url');
```

Expected:

```text
Values match .env
```

---

# Phase 6 — Build urlscan.io Laravel Client

## Goal

Create a single Laravel service responsible for all urlscan.io HTTP communication.

## Files to create

```text
app/Services/Urlscan/UrlscanClient.php
```

## Methods to implement

```php
public function submitUrl(string $url): array
public function getResult(string $scanId): array
public function getDom(string $scanId): string
```

## Required HTTP behavior

All requests must:

- Use `config('urlscan.base_url')`.
- Send `api-key` header using `config('urlscan.api_key')`.
- Use timeout from config.
- Throw meaningful exceptions on failure.
- Handle HTTP 429 as a rate-limit case.
- Not hide error details from Laravel logs.

## Endpoint behavior

### `submitUrl(string $url)`

Send:

```http
POST /api/v1/scan/
```

Body:

```json
{
  "url": "https://example.com",
  "visibility": "unlisted",
  "tags": ["phishing-rod", "thesis-demo"]
}
```

Expected return should include enough data to store:

```text
urlscan_scan_id
urlscan_result_url
raw_submission_response
```

### `getResult(string $scanId)`

Send:

```http
GET /api/v1/result/{scanId}/
```

Return decoded JSON array.

### `getDom(string $scanId)`

Send:

```http
GET /dom/{scanId}/
```

Return raw HTML string.

## Acceptance tests

Before using real jobs, test methods from Tinker or a temporary route only in local development.

Expected:

```text
submitUrl() returns urlscan submission response
No API key produces a clear configuration/authentication error
Invalid scan ID produces a controlled exception
```

Remove any temporary test route after testing.

---

# Phase 7 — Add Artifact Storage Helpers

## Goal

Store urlscan result JSON and DOM/HTML safely on disk and track them in the database.

## Files to create

```text
app/Services/Scans/ScanArtifactStorage.php
```

## Methods to implement

```php
public function storeJson(Scan $scan, string $type, array $data): ScanArtifact
public function storeText(Scan $scan, string $type, string $content, string $extension, string $contentType): ScanArtifact
public function readArtifact(ScanArtifact $artifact): string
```

## Storage path convention

```text
storage/app/scans/{scan_uuid}/result.json
storage/app/scans/{scan_uuid}/dom.html
```

Use Laravel Storage facade. Avoid hard-coded absolute paths.

## Artifact metadata

When storing an artifact, save:

```text
scan_id
type
storage_path
sha256
size_bytes
content_type
```

## Expected artifact types

```text
result_json
dom_html
```

Possibly later:

```text
screenshot
response_body
```

## Acceptance tests

After manually calling storage helper:

```text
File exists under storage/app/scans/{uuid}/...
scan_artifacts row exists
sha256 is filled
size_bytes is filled
content_type is correct
```

---

# Phase 8 — Replace Mock Queue With urlscan.io Jobs

## Goal

Build real async scan retrieval using urlscan.io.

## Jobs to create

```bash
php artisan make:job SubmitUrlscanJob
php artisan make:job FetchUrlscanResultJob
php artisan make:job FetchUrlscanDomJob
```

## 8.1 `SubmitUrlscanJob`

Constructor:

```php
public function __construct(public int $scanId) {}
```

Responsibilities:

1. Load scan.
2. Set scan status to `submitted_to_urlscan`.
3. Call `UrlscanClient::submitUrl($scan->normalized_url)`.
4. Create or update `urlscan_submissions` row.
5. Store `urlscan_scan_id`, result URL, raw submission response, visibility, and `submitted_at`.
6. Dispatch `FetchUrlscanResultJob` with scan ID.
7. On failure:
   - Set scan `status = failed`.
   - Set `error_message`.
   - Log exception.

## 8.2 `FetchUrlscanResultJob`

Responsibilities:

1. Load scan and its `urlscanSubmission`.
2. Set status to `waiting_for_urlscan`.
3. Call `UrlscanClient::getResult($urlscanScanId)`.
4. If result is not ready yet:
   - Release/retry job with delay.
   - Do not mark scan failed immediately.
5. If result is available:
   - Store result JSON using `ScanArtifactStorage`.
   - Update `urlscan_submissions.result_fetched_at`.
   - Set scan status to `urlscan_complete`.
   - Dispatch `FetchUrlscanDomJob`.

Retry/backoff guidance:

```text
Initial delay: 10 seconds
Then: 20 seconds
Then: 30 seconds
Maximum attempts: reasonable local value such as 10
```

## 8.3 `FetchUrlscanDomJob`

Responsibilities:

1. Load scan and `urlscanSubmission`.
2. Call `UrlscanClient::getDom($urlscanScanId)`.
3. Store DOM HTML using `ScanArtifactStorage`.
4. Update `urlscan_submissions.dom_fetched_at`.
5. Set scan status to `dom_fetched`.
6. Dispatch `RunPredictionJob` later, once Phase 10 exists.

For now, before Phase 10, this job may temporarily set:

```text
status = completed
verdict = null
confidence = null
completed_at = now()
```

or stop at `dom_fetched`.

Preferred before ML integration:

```text
status = dom_fetched
```

## Dispatch chain

After `POST /api/scans`, dispatch:

```php
SubmitUrlscanJob::dispatch($scan->id);
```

Do not call urlscan.io directly from the controller.

## Acceptance tests

1. Start queue worker:

```bash
php artisan queue:work
```

2. Submit a scan:

```http
POST /api/scans
```

3. Poll:

```http
GET /api/scans/{uuid}
```

4. Expected status progression:

```text
queued
submitted_to_urlscan
waiting_for_urlscan
urlscan_complete
dom_fetched
```

5. Expected database/storage state:

```text
urlscan_submissions row exists
result_json artifact exists
dom_html artifact exists
storage/app/scans/{uuid}/result.json exists
storage/app/scans/{uuid}/dom.html exists
```

---

# Phase 9 — Create Python ML Service Skeleton

## Goal

Create the internal Python service before connecting Laravel to it.

## Location

Create sibling folder beside Laravel app or inside a monorepo root:

```text
phishing-rod-backend/
├── phishing-rod/
└── ml-service/
```

## Directory structure

```text
ml-service/
├── app/
│   ├── main.py
│   ├── predictor.py
│   ├── schemas.py
│   ├── model_loader.py
│   └── feature_extraction/
│       ├── url_features.py
│       └── html_features_enhanced.py
├── models/
│   ├── best_combined_model.joblib
│   ├── best_html_enhanced_model.joblib
│   └── best_url_model.joblib
├── requirements.txt
└── .env
```

## Required runtime model files

Only the following **three runtime model files** must be placed in `ml-service/models/`:

```text
best_combined_model.joblib
best_html_enhanced_model.joblib
best_url_model.joblib
```

The older/basic HTML model is **not** part of the runtime service because it was replaced by the enhanced HTML model. If the old file `best_html_model.joblib` exists in an archive or training folder, keep it there for historical comparison only. Do not copy it into `ml-service/models/`, do not expose it through `/predict`, and do not include it in the active model-selection logic.

The current intended default model is:

```text
best_combined_model.joblib
```

The Python service should treat these as the only allowed runtime model names:

```text
best_combined_model.joblib
best_html_enhanced_model.joblib
best_url_model.joblib
```

`best_html_model.joblib` should be considered deprecated and unavailable at runtime.

## Required feature extraction files

The Python service must include feature extraction code matching the uploaded files:

```text
url_features.py
HtmlFeatureExtract_enhanced.py
```

Rename inside service if desired:

```text
HtmlFeatureExtract_enhanced.py -> html_features_enhanced.py
```

but do not change function behavior unless the model is retrained.

Required runtime functions:

```python
extract_url_features(url: str) -> dict
extract_html_features(html: str, url: str) -> dict
```

## FastAPI endpoints

### `GET /health`

Response:

```json
{
  "status": "ok"
}
```

### `POST /predict`

Request:

```json
{
  "url": "https://example.com",
  "dom_html": "<html>...</html>",
  "urlscan_result": {},
  "model_name": "best_combined_model.joblib"
}
```

Temporary mock response for skeleton phase:

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

## Internal token

The service should expect:

```http
Authorization: Bearer dev-internal-token-change-me
```

Token value should come from Python `.env`.

## Run locally

```bash
cd ml-service
python -m venv venv
venv\Scripts\activate
pip install -r requirements.txt
uvicorn app.main:app --host 127.0.0.1 --port 9000 --reload
```

## Acceptance tests

```bash
curl http://127.0.0.1:9000/health
```

Expected:

```json
{"status":"ok"}
```

Test predict with token:

```bash
curl -X POST http://127.0.0.1:9000/predict ^
  -H "Content-Type: application/json" ^
  -H "Authorization: Bearer dev-internal-token-change-me" ^
  -d "{\"url\":\"https://example.com\",\"dom_html\":\"<html></html>\"}"
```

Expected:

```text
HTTP 200
Mock prediction JSON returned
```

---

# Phase 10 — Add Real Python Feature Extraction and Model Loading

## Goal

Turn the Python mock service into a real feature extraction and inference service.

## Python service tasks

### 10.1 Move/adapt feature extractors

Copy these files into:

```text
ml-service/app/feature_extraction/
```

Expected final files:

```text
ml-service/app/feature_extraction/url_features.py
ml-service/app/feature_extraction/html_features_enhanced.py
```

The URL extractor must provide:

```python
extract_url_features(url: str) -> dict
```

The HTML extractor must provide:

```python
extract_html_features(html: str, url: str) -> dict
```

### 10.2 Create model loader

Create:

```text
ml-service/app/model_loader.py
```

Responsibilities:

1. Locate model file under `ml-service/models/`.
2. Enforce the runtime model allowlist:
   - `best_combined_model.joblib`
   - `best_html_enhanced_model.joblib`
   - `best_url_model.joblib`
3. Reject `best_html_model.joblib` and any other unknown model name with a clear error.
4. Load `.joblib` using `joblib.load()`.
5. Cache loaded models in memory.
6. Return selected model by name.
7. Fail clearly if model file is missing.

### 10.3 Create feature schema files

Create schema files if they do not already exist:

```text
ml-service/models/feature_schemas/
├── url_feature_schema.json
├── html_enhanced_feature_schema.json
└── combined_feature_schema.json
```

Each schema must contain exact feature order expected by its model:

```json
{
  "model_name": "best_combined_model.joblib",
  "schema_version": "combined-v1",
  "features": [
    "url_length",
    "hostname_length",
    "path_length"
  ]
}
```

Important: The sample above is not complete. The final schema must match the training data exactly.

### 10.4 Implement predictor

Create/update:

```text
ml-service/app/predictor.py
```

Responsibilities:

1. Receive URL, DOM HTML, optional urlscan result, and optional model name.
2. Extract URL features.
3. Extract enhanced HTML features if DOM HTML is present.
4. Use model-specific feature groups:
   - `best_url_model.joblib` uses only URL features.
   - `best_html_enhanced_model.joblib` uses only enhanced HTML features.
   - `best_combined_model.joblib` uses URL features plus enhanced HTML features.
5. Merge URL + enhanced HTML features only for the combined model.
6. Select the correct feature schema.
7. Build ordered feature row.
8. Apply preprocessor if one exists.
9. Call `model.predict_proba()` if available.
10. Convert probabilities into label and confidence.
11. Return label, probabilities, model name, schema version, and feature dictionaries.

### 10.5 Probability handling

If model has two classes, expected output:

```text
safe_probability
phishing_probability
```

If model class order is unknown, inspect `model.classes_` and map probabilities correctly.

Do not assume index `0` is safe unless confirmed.

### 10.6 Label thresholds

Suggested MVP thresholds:

```text
phishing_probability >= 0.75 -> phishing
0.45 <= phishing_probability < 0.75 -> suspicious
phishing_probability < 0.45 -> safe
```

These can be adjusted later.

## Acceptance tests

1. Run `/health`.
2. Run `/predict` with:

```json
{
  "url": "https://example.com",
  "dom_html": "<html><title>Example</title></html>",
  "model_name": "best_combined_model.joblib"
}
```

3. Expected:

```text
HTTP 200
Response includes label
Response includes confidence
Response includes safe_probability
Response includes phishing_probability
Response includes model_name
Response includes features
No feature order error
No model loading error
```

---

# Phase 11 — Connect Laravel to Python ML Service

## Goal

Allow Laravel to call the internal Python `/predict` endpoint.

## Files to create

```text
app/Services/Ml/MlPredictionClient.php
php artisan make:job RunPredictionJob
```

## `MlPredictionClient` responsibilities

Method:

```php
public function predict(array $payload): array
```

Behavior:

1. Send POST request to `config('ml.base_url') . '/predict'`.
2. Include `Authorization: Bearer {config('ml.token')}`.
3. Include JSON payload.
4. Use timeout from config.
5. Throw controlled exception on failure.
6. Return decoded JSON array.

Payload shape:

```php
[
    'url' => $scan->normalized_url,
    'dom_html' => $domHtml,
    'urlscan_result' => $urlscanResultJson,
    'model_name' => config('ml.active_model'),
]
```

## `RunPredictionJob` responsibilities

1. Load scan.
2. Set status to `predicting`.
3. Read `dom_html` artifact from storage.
4. Read `result_json` artifact from storage.
5. Call `MlPredictionClient::predict()`.
6. Store feature dictionaries into `feature_sets`.
7. Store model result into `predictions`.
8. Update simple fields on `scans`:
   - `verdict`
   - `confidence` as percentage, not decimal probability
   - `status = completed`
   - `completed_at = now()`
9. On failure:
   - Set `status = failed`
   - Store `error_message`
   - Log exception

## Confidence conversion rule

Python returns probability as decimal:

```text
0.9425
```

Laravel stores percentage in `scans.confidence`:

```text
94.25
```

If `predictions.confidence` also stores percentage, keep it consistent. Do not store `0.9425` in one place and `94.25` in another without documenting it.

Recommended:

```text
Laravel database stores percentages in decimal(5,2)
Python API returns decimal probabilities
```

## Acceptance tests

1. Start Python service.
2. Start Laravel queue worker.
3. Manually create a scan and attach test DOM/result artifacts, or use a scan that already reached `dom_fetched`.
4. Dispatch `RunPredictionJob`.
5. Expected:

```text
feature_sets row created
predictions row created
scans.status = completed
scans.verdict filled
scans.confidence filled as percentage
```

---

# Phase 12 — Full End-to-End Scan Pipeline

## Goal

Connect all jobs into the final MVP scan pipeline.

## Final job chain

```text
POST /api/scans
        |
        v
Create Scan row with status queued
        |
        v
SubmitUrlscanJob
        |
        v
FetchUrlscanResultJob
        |
        v
FetchUrlscanDomJob
        |
        v
RunPredictionJob
        |
        v
Scan status completed or failed
```

## Status sequence

Expected successful sequence:

```text
queued
submitted_to_urlscan
waiting_for_urlscan
urlscan_complete
dom_fetched
predicting
completed
```

Expected failure:

```text
failed
```

`error_message` should explain the failure at a high level.

## Update `FetchUrlscanDomJob`

At the end of the job, dispatch:

```php
RunPredictionJob::dispatch($scan->id);
```

## Update `ScanResource`

Include nested prediction data if loaded or available:

```php
'prediction' => $this->whenLoaded('prediction', function () {
    return [
        'label' => $this->prediction->label,
        'confidence' => $this->prediction->confidence,
        'safe_probability' => $this->prediction->safe_probability,
        'phishing_probability' => $this->prediction->phishing_probability,
        'model_name' => $this->prediction->model_name,
        'model_version' => $this->prediction->model_version,
    ];
}),
```

If not using eager loading, either load it in controller or keep simple fields on `scans`.

## Controller `show` recommendation

```php
$scan = Scan::with(['prediction', 'urlscanSubmission'])
    ->where('uuid', $uuid)
    ->firstOrFail();
```

## Acceptance tests

1. Start Laravel:

```bash
php artisan serve
```

2. Start queue worker:

```bash
php artisan queue:work
```

3. Start Python service:

```bash
uvicorn app.main:app --host 127.0.0.1 --port 9000 --reload
```

4. Submit scan:

```http
POST http://127.0.0.1:8000/api/scans
```

5. Poll:

```http
GET http://127.0.0.1:8000/api/scans/{uuid}
```

6. Expected final response:

```json
{
  "data": {
    "uuid": "...",
    "submitted_url": "https://example.com",
    "domain": "example.com",
    "status": "completed",
    "verdict": "safe",
    "confidence": "87.00",
    "completed_at": "..."
  }
}
```

---

# Phase 13 — API Safety, Abuse Prevention, and URL Hardening

## Goal

Make the public anonymous API safer before any real deployment.

## Tasks

### 13.1 Add rate limiting

Add a route limiter such as:

```text
anonymous scans: 5 per minute per IP for local/demo
```

Apply to scan creation route only:

```php
Route::post('/scans', [ScanController::class, 'store'])
    ->middleware('throttle:scans');
```

Implementation location depends on Laravel version. In Laravel 11/12 style apps, route limiting may be configured in `bootstrap/app.php` or a service provider depending on project setup.

### 13.2 Strengthen URL validation

`UrlValidatorService` should block:

```text
localhost
127.0.0.1
0.0.0.0
::1
10.0.0.0/8
172.16.0.0/12
192.168.0.0/16
169.254.0.0/16
file://
ftp://
gopher://
javascript:
data:
```

Even though urlscan.io does the actual browsing, the backend should not accept obviously internal or unsupported URLs.

### 13.3 Hide raw artifacts from public API

Do not expose raw DOM or full urlscan result JSON by default.

Public response should show:

```text
status
verdict
confidence
model name
basic timestamps
maybe high-level explanation later
```

### 13.4 Store privacy-sensitive data carefully

If storing IP addresses, prefer hashed values:

```text
source_ip_hash
```

Do not store unnecessary personal data.

## Acceptance tests

```text
Invalid schemes rejected with HTTP 422
Localhost/private IP URLs rejected
Too many POST /api/scans requests trigger HTTP 429
GET /api/scans/{uuid} does not expose raw DOM HTML
```

---

# Phase 14 — Testing and Developer Tooling

## Goal

Make the backend safe for iterative development.

## Test files to create

```text
tests/Feature/Api/ScanSubmissionTest.php
tests/Feature/Api/ScanShowTest.php
tests/Unit/Services/UrlscanClientTest.php
tests/Unit/Services/MlPredictionClientTest.php
tests/Unit/Services/UrlValidatorServiceTest.php
```

## Required tests

### Scan submission tests

Test cases:

```text
valid URL creates scan
invalid URL returns 422
missing URL returns 422
created scan has UUID
created scan has queued status
response does not expose internal ID as primary identifier
```

### Scan show tests

Test cases:

```text
existing UUID returns scan
missing UUID returns 404
completed scan returns verdict and confidence
failed scan returns error_message
```

### Urlscan client tests

Use HTTP fake/mocking.

Test cases:

```text
submitUrl sends api-key header
submitUrl sends visibility unlisted
getResult handles successful JSON response
getDom handles HTML response
429 response produces controlled exception or retry signal
```

### ML client tests

Use HTTP fake/mocking.

Test cases:

```text
predict sends Authorization bearer token
predict sends URL and DOM HTML
predict returns decoded JSON
timeout/failure is handled clearly
```

## Commands

```bash
php artisan test
```

## Acceptance criteria

```text
All tests pass
No real urlscan.io calls in tests
No real Python service calls in tests
```

---

# Phase 15 — Documentation and Runbook

## Goal

Make it easy for another person or AI tool to run the backend.

## Files to create/update

```text
README.md
.env.example
docs/api.md
docs/local-development.md
```

## `README.md` should include

```text
Project purpose
Requirements
Installation steps
PostgreSQL setup
Migration commands
Queue worker command
Python service command
Postman/cURL examples
```

## `.env.example` should include

```env
APP_NAME=PhishingRod
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=phishing_rod
DB_USERNAME=postgres
DB_PASSWORD=

QUEUE_CONNECTION=database

URLSCAN_BASE_URL=https://urlscan.io
URLSCAN_API_KEY=
URLSCAN_VISIBILITY=unlisted
URLSCAN_TIMEOUT=30

ML_SERVICE_URL=http://127.0.0.1:9000
ML_SERVICE_TOKEN=dev-internal-token-change-me
ML_SERVICE_TIMEOUT=30
ML_ACTIVE_MODEL=best_combined_model.joblib
```

## `docs/api.md` should document

```text
POST /api/scans
GET /api/scans/{uuid}
GET /api/scans
Example request bodies
Example success responses
Example validation errors
Status meanings
```

## Acceptance criteria

A new developer should be able to read the docs and run:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
php artisan queue:work
```

and understand how to run the Python service separately.

---

# Phase 16 — Optional Future Backend Features

These are not MVP tasks. Do not implement them until the core scan pipeline works.

## User accounts

Later, add Laravel authentication and attach scans to users:

```text
scans.user_id nullable foreign key
```

Anonymous scans remain possible.

## API keys

Later, users may have API keys for programmatic access.

## Scan history dashboard

Later frontend can show previous scans by user.

## Admin model management

Later admin panel can manage active model versions.

## Caching duplicate scans

Later, if the same URL is scanned repeatedly within a short time, reuse recent result.

## Explanation endpoint

Later, add a safe explanation output based on feature values:

```text
Long URL
Login form detected
External form action
Suspicious keywords found
```

Do not expose full feature vectors publicly unless needed.

---

# Final Backend Completion Definition

The backend MVP is considered complete when all of the following are true:

```text
POST /api/scans creates an anonymous scan
GET /api/scans/{uuid} returns current scan state
Queue worker processes scans asynchronously
Laravel submits URLs to urlscan.io
Laravel fetches urlscan.io result JSON
Laravel fetches urlscan.io DOM/HTML
Artifacts are stored on disk and tracked in DB
Laravel calls internal Python ML service
Python service extracts URL and HTML features
Python service loads provided .joblib models
Python service returns probabilities and label
Laravel stores features and prediction
Final API response includes verdict and confidence
Invalid URLs are rejected
Rate limiting exists
Raw DOM is not exposed publicly
Tests cover the main API and service clients
```

The intended final API result should look like:

```json
{
  "data": {
    "uuid": "scan-uuid",
    "submitted_url": "https://example.com",
    "normalized_url": "https://example.com",
    "domain": "example.com",
    "status": "completed",
    "verdict": "safe",
    "confidence": "87.00",
    "error_message": null,
    "completed_at": "2026-06-27T12:00:00.000000Z",
    "created_at": "2026-06-27T11:59:30.000000Z",
    "updated_at": "2026-06-27T12:00:00.000000Z",
    "prediction": {
      "label": "safe",
      "confidence": "87.00",
      "safe_probability": "87.00",
      "phishing_probability": "13.00",
      "model_name": "best_combined_model.joblib",
      "model_version": "combined-v1"
    }
  }
}
```

This document should be read together with `phishing_rod_project_brief.md`. The project brief explains the overall system, ML feature extraction requirements, model files, and urlscan.io purpose. This document explains the backend phases required to implement that system reliably.
