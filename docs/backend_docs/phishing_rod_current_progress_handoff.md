# Phishing Rod Current Progress Handoff

**Document purpose:** This Markdown file complements the main project brief and the backend phased implementation plan. The project brief explains the complete Laravel + Python ML system. The phased backend plan explains what still needs to be built. This handoff explains **what has already been done so far**, what is currently working, what decisions have already been made, and what the next developer or AI coding assistant should assume before continuing.

**Project name:** Phishing Rod  
**Laravel app name:** `phishing-rod`  
**Backend framework:** Laravel API backend  
**Database:** PostgreSQL  
**Database management tool:** pgAdmin  
**API style:** API-first using `routes/api.php`  
**Frontend/Blade status:** Not being used for the MVP  
**Authentication status:** No login for MVP  
**ML runtime:** Separate internal Python/FastAPI service planned, not implemented yet  
**External scan provider:** urlscan.io planned, not integrated yet

---

## 1. How This File Should Be Used

This file should be read together with:

```text
phishing_rod_project_brief.md
phishing_rod_backend_phased_plan.md
```

The project brief describes the full target system, including Laravel, urlscan.io, Python feature extraction, and the three active model files. The backend phased plan describes the future implementation phases. This handoff is only about the **current state of the project** and the work already completed in the Laravel backend.

Any human developer or AI coding assistant continuing the project should first read this file to understand the current baseline before modifying code.

---

## 2. Current Project State Summary

The Laravel backend has been initialized and is no longer an empty project. PostgreSQL has been connected successfully. The first application table, `scans`, has been created through a Laravel migration. A basic API-based URL submission flow has been implemented and tested in Postman.

The current backend can do this:

```text
Client / Postman
        |
        v
POST /api/scans with a URL
        |
        v
Laravel validates basic URL input
        |
        v
Laravel creates a scan row in PostgreSQL
        |
        v
Laravel returns a JSON response with scan data
```

The current backend does **not** yet do this:

```text
Submit URL to urlscan.io
Fetch DOM/HTML from urlscan.io
Call Python ML service
Extract features at runtime
Load .joblib models
Return a real phishing confidence score
Run asynchronous queue processing
```

Those parts are planned in the backend phased implementation document but have not been completed yet.

---

## 3. Project Directory Context

The Laravel app was created under a backend folder structure similar to:

```text
D:\uniBooks\Theisis\WebApp\phishingRod-backend\phishing-rod
```

The exact local path may differ if moved later, but the important project directory is:

```text
phishing-rod/
```

Commands should be run from inside this Laravel project directory unless stated otherwise:

```bash
cd phishing-rod
```

---

## 4. Database Work Completed

PostgreSQL is the selected database. The user already has pgAdmin installed and uses it to inspect the database.

### Database name

```text
phishing_rod
```

### Laravel `.env` database configuration

The Laravel `.env` should be configured for PostgreSQL similar to:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=phishing_rod
DB_USERNAME=postgres
DB_PASSWORD=your_postgres_password
```

The actual password should remain local and should not be committed to Git.

### Database connection confirmation

The following command was run successfully:

```bash
php artisan config:clear
```

Then this command was run:

```bash
php artisan migrate:status
```

At first, Laravel returned:

```text
Migration table not found.
```

This was interpreted correctly: it meant Laravel could reach the PostgreSQL database, but the Laravel migrations table had not been created yet. After that, migrations were run with:

```bash
php artisan migrate
```

The database connection was successfully established.

---

## 5. pgAdmin Table Location

The tables can be seen in pgAdmin under:

```text
phishing_rod
└── Schemas
    └── public
        └── Tables
```

If tables do not appear immediately, refresh the `Tables` node in pgAdmin.

The main application table created so far is:

```text
scans
```

Laravel uses the plural table name `scans`, not `scan`.

---

## 6. Laravel App Initialization Completed

The Laravel app was initialized with the project name:

```text
phishing-rod
```

The app can be served locally with:

```bash
php artisan serve
```

Expected local URL:

```text
http://127.0.0.1:8000
```

The Laravel dev server being active only confirms the app can run. Database connectivity was separately confirmed through migration commands.

---

## 7. First Application Model and Migration Completed

A `Scan` model and migration were created with:

```bash
php artisan make:model Scan -m
```

Expected files:

```text
app/Models/Scan.php
database/migrations/*_create_scans_table.php
```

The `scans` table was designed as the first core table for submitted URL scans.

### Current expected `scans` table columns

The migration should include fields similar to:

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

### Purpose of current columns

| Column           | Purpose                                                                    |
| ---------------- | -------------------------------------------------------------------------- |
| `id`             | Internal database ID. Should not be the main public identifier.            |
| `uuid`           | Public scan identifier used in API URLs.                                   |
| `submitted_url`  | The exact URL submitted by the client.                                     |
| `normalized_url` | Cleaned version of the submitted URL. Currently simple normalization only. |
| `domain`         | Host/domain extracted from the URL.                                        |
| `status`         | Current scan lifecycle status. Currently starts as `queued`.               |
| `verdict`        | Placeholder for future result such as `safe`, `suspicious`, or `phishing`. |
| `confidence`     | Placeholder for future prediction confidence percentage.                   |
| `error_message`  | Placeholder for failure information.                                       |
| `completed_at`   | Future timestamp for completed scans.                                      |
| `created_at`     | Laravel timestamp.                                                         |
| `updated_at`     | Laravel timestamp.                                                         |

### Current `Scan` model fillable fields

`app/Models/Scan.php` should include fillable fields similar to:

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

If not already added, the following casts are recommended next:

```php
protected $casts = [
    'confidence' => 'decimal:2',
    'completed_at' => 'datetime',
];
```

---

## 8. API-First Direction Chosen

A design decision has already been made: the backend should focus on API routes, not Blade pages.

### Use this

```text
routes/api.php
API controllers
JSON responses
Postman testing
```

### Do not focus on this for MVP

```text
routes/web.php
Blade templates
Laravel frontend pages
Login/authentication scaffolding
```

The MVP backend is intended to act as a JSON API that a future frontend can consume.

---

## 9. API Controller Created

An API controller was created with a command similar to:

```bash
php artisan make:controller Api/ScanController
```

Expected file:

```text
app/Http/Controllers/Api/ScanController.php
```

The controller is responsible for the initial scan endpoints.

---

## 10. API Routes Added

The current expected API routes are:

```php
use App\Http\Controllers\Api\ScanController;
use Illuminate\Support\Facades\Route;

Route::post('/scans', [ScanController::class, 'store']);
Route::get('/scans/{uuid}', [ScanController::class, 'show']);
Route::get('/scans', [ScanController::class, 'index']);
```

Because these routes are in `routes/api.php`, the real local URLs are:

```text
POST http://127.0.0.1:8000/api/scans
GET  http://127.0.0.1:8000/api/scans/{uuid}
GET  http://127.0.0.1:8000/api/scans
```

The route list can be checked with:

```bash
php artisan route:list --path=api
```

Expected routes should include:

```text
POST      api/scans
GET|HEAD  api/scans/{uuid}
GET|HEAD  api/scans
```

---

## 11. Postman Testing Completed

Postman has been used to test the API.

### Successful request format

```text
Method: POST
URL: http://127.0.0.1:8000/api/scans
```

Headers:

```text
Accept: application/json
Content-Type: application/json
```

Body:

```json
{
  "url": "https://example.com"
}
```

Expected successful behavior:

```text
Laravel creates a row in scans table.
Laravel returns JSON response.
Returned scan status is queued.
Returned scan has a uuid.
```

---

## 12. Important Bug Fixed

During Postman testing, the backend returned an internal server error:

```text
Class "App\Http\Controllers\Api\Scan" not found
```

The failure happened at this line in the API controller:

```php
$scan = Scan::create([...]);
```

### Cause

Inside `App\Http\Controllers\Api\ScanController`, PHP interpreted `Scan` as:

```text
App\Http\Controllers\Api\Scan
```

instead of the model:

```text
App\Models\Scan
```

### Fix

The following import was added at the top of `app/Http/Controllers/Api/ScanController.php`:

```php
use App\Models\Scan;
```

The controller also needs:

```php
use Illuminate\Support\Str;
```

if it generates UUIDs using:

```php
Str::uuid()->toString()
```

After this import fix, the API worked correctly.

---

## 13. Current Controller Behavior

The current API controller is expected to do the following in `store()`:

```text
1. Validate that url exists and is a URL.
2. Store the submitted URL.
3. Normalize it lightly by removing trailing slash.
4. Extract the domain using parse_url().
5. Create a Scan row with a UUID.
6. Set status to queued.
7. Return JSON response with HTTP 201.
```

The current simplified logic is approximately:

```php
$validated = $request->validate([
    'url' => ['required', 'url', 'max:2048'],
]);

$submittedUrl = $validated['url'];
$normalizedUrl = rtrim($submittedUrl, '/');
$domain = parse_url($normalizedUrl, PHP_URL_HOST);

$scan = Scan::create([
    'uuid' => Str::uuid()->toString(),
    'submitted_url' => $submittedUrl,
    'normalized_url' => $normalizedUrl,
    'domain' => $domain,
    'status' => 'queued',
]);

return response()->json([
    'message' => 'Scan created successfully.',
    'data' => $scan,
], 201);
```

This is acceptable for the current early baseline.

---

## 14. API Cleanup Step Defined

A cleanup step has been defined and should be applied if it has not already been implemented.

The cleanup introduces:

```text
StoreScanRequest
ScanResource
Cleaner Api\ScanController
```

### Intended request class

Expected file:

```text
app/Http/Requests/Api/StoreScanRequest.php
```

Expected validation:

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

`authorize()` must return:

```php
return true;
```

### Intended resource class

Expected file:

```text
app/Http/Resources/ScanResource.php
```

The resource should expose:

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

The resource should avoid requiring clients to use the internal numeric `id`.

### Current status of cleanup

If this cleanup has already been applied, continue from the next backend phase. If it has not been applied, this should be the next immediate implementation step before queues or urlscan.io are added.

---

## 15. Current Working Backend Baseline

A developer continuing the work should assume the backend baseline is:

```text
Laravel app exists.
PostgreSQL is connected.
Initial migrations have run.
scans table exists.
Scan model exists.
routes/api.php contains scan endpoints.
Api\ScanController exists.
POST /api/scans works in Postman after importing App\Models\Scan.
Basic URL submissions are saved to PostgreSQL.
```

The current backend is still only a simple scan-record creation API. It does not yet perform real scanning.

---

## 16. Decisions Already Made

### 16.1 No login for MVP

The first version of the application should remain anonymous and public. User accounts can be added later.

### 16.2 API-first backend

The backend should use JSON APIs rather than Blade pages.

### 16.3 Laravel owns orchestration

Laravel is responsible for:

```text
API routes
Request validation
Database records
Queue jobs
urlscan.io API communication
Calling Python service
Returning scan status/results
```

### 16.4 Python owns ML

Python is responsible for:

```text
URL feature extraction
HTML feature extraction
Loading .joblib models
Matching feature schemas
Returning prediction probabilities
```

### 16.5 urlscan.io will retrieve HTML/DOM

Laravel should not directly visit suspicious submitted URLs. urlscan.io will be used as the safe retrieval layer for DOM/HTML and scan metadata.

### 16.6 Only three active models will be used

The active runtime model set is:

```text
best_combined_model.joblib
best_html_enhanced_model.joblib
best_url_model.joblib
```

The older basic HTML model:

```text
best_html_model.joblib
```

should be treated as deprecated/archived because the enhanced HTML model replaces it. It should not be copied into the active Python runtime model folder, exposed through `/predict`, or used in active model-selection logic.

---

## 17. Python Service Status

The Python service has been planned but not yet created in the backend project.

Expected future directory structure:

```text
phishing-rod-backend/
├── phishing-rod/
└── ml-service/
```

Expected future Python service structure:

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

The Python service should eventually expose:

```text
GET /health
POST /predict
```

Laravel should call it through an internal HTTP client, not by importing Python code.

---

## 18. Feature Extraction Files Already Identified

The project already has feature extraction files that the Python service must match:

```text
url_features.py
HtmlFeatureExtract_enhanced.py
```

The required runtime functions are:

```python
extract_url_features(url: str) -> dict
extract_html_features(html: str, url: str) -> dict
```

The feature extraction behavior must not be casually changed unless the models are retrained or the feature schemas are updated.

---

## 19. Model Files Already Identified

The active model files for the Python service are:

```text
best_combined_model.joblib
best_html_enhanced_model.joblib
best_url_model.joblib
```

The expected default model is:

```text
best_combined_model.joblib
```

The deprecated old model is:

```text
best_html_model.joblib
```

It should remain outside the active runtime path unless intentionally archived for reference.

---

## 20. urlscan.io Status

urlscan.io has been selected as the safe external scanning provider, but integration has not yet been implemented in Laravel.

Expected future `.env` values:

```env
URLSCAN_BASE_URL=https://urlscan.io
URLSCAN_API_KEY=your_urlscan_api_key_here
URLSCAN_VISIBILITY=unlisted
URLSCAN_TIMEOUT=30
```

Expected future service:

```text
app/Services/Urlscan/UrlscanClient.php
```

Expected future methods:

```php
submitUrl(string $url): array
getResult(string $scanId): array
getDom(string $scanId): string
```

The DOM/HTML should be fetched from urlscan.io, stored as an artifact, and then sent to the Python service.

---

## 21. Queue Status

Queue processing has been planned but not implemented yet.

Expected local queue configuration:

```env
QUEUE_CONNECTION=database
```

Expected future first queue test:

```text
Create ProcessScanJob
Dispatch it after scan creation
Have it change status from queued -> processing -> completed with mock result
Confirm queue worker updates PostgreSQL
```

This mock queue phase should happen before real urlscan.io integration.

Expected command to run queue worker later:

```bash
php artisan queue:work
```

---

## 22. What Should Be Done Next

The next developer or AI tool should continue in this order.

### Step 1: Confirm API cleanup

Check whether these exist:

```text
app/Http/Requests/Api/StoreScanRequest.php
app/Http/Resources/ScanResource.php
```

If they do not exist, create them first and update `Api\ScanController` to use them.

### Step 2: Confirm API still works

Run:

```bash
php artisan serve
```

Test:

```text
POST http://127.0.0.1:8000/api/scans
```

with:

```json
{
  "url": "https://example.com"
}
```

Expected:

```text
HTTP 201
JSON response
status = queued
row created in scans table
```

### Step 3: Add backend domain structure

Implement the next phase from the backend phased plan:

```text
ScanStatus enum
NormalizeUrlAction
CreateScanAction
UrlValidatorService skeleton
```

Do not integrate urlscan.io yet.

### Step 4: Add database queue with mock job

Add a `ProcessScanJob` that updates status with a fake result. This proves asynchronous processing works before using urlscan.io.

### Step 5: Add urlscan.io configuration and client

Only after the mock queue works, create `config/urlscan.php` and `UrlscanClient`.

### Step 6: Add Python mock service

Create the FastAPI service with mock `/predict` before loading real models.

### Step 7: Add real feature extraction and three active models

Move in the feature extractor code and the three active `.joblib` models.

---

## 23. Known Issues and Cautions

### 23.1 Do not forget model imports

The project already encountered a missing import issue with `Scan::create()`. In Laravel controllers or services, always import model classes explicitly:

```php
use App\Models\Scan;
```

### 23.2 Use `Accept: application/json` in Postman

When testing API routes in Postman, include:

```text
Accept: application/json
```

Without this header, Laravel may return a large HTML error page instead of clean JSON errors.

### 23.3 Do not build Blade UI now

The user specifically wants to focus on the API backend. Blade can be ignored for now.

### 23.4 Do not skip queue testing

Before integrating urlscan.io and Python, a mock queue job should be tested. This prevents mixing queue problems with external API problems.

### 23.5 Do not treat the old HTML model as active

Only the enhanced HTML model should be active. The old basic HTML model is replaced by the enhanced one.

---

## 24. Current Completion Checklist

Use this checklist to verify current progress.

```text
[x] Laravel app initialized as phishing-rod
[x] PostgreSQL selected as database
[x] pgAdmin available for DB inspection
[x] Database phishing_rod created/used
[x] Laravel .env configured for PostgreSQL
[x] Laravel database connection tested through migrations
[x] Initial migrations run
[x] Scan model created
[x] scans table created
[x] API-first direction chosen
[x] Api\ScanController created
[x] routes/api.php scan endpoints added
[x] Postman used for API testing
[x] Missing App\Models\Scan import bug identified and fixed
[x] POST /api/scans working for simple URL submission
[ ] StoreScanRequest confirmed/applied
[ ] ScanResource confirmed/applied
[ ] ScanStatus enum created
[ ] Queue infrastructure added
[ ] Mock ProcessScanJob added
[ ] urlscan.io config/client added
[ ] Artifact storage added
[ ] Python FastAPI service created
[ ] Real feature extraction connected
[ ] Three active models loaded
[ ] Laravel connected to Python service
[ ] End-to-end scan pipeline completed
```

---

## 25. Final Current State in One Paragraph

At the time of this handoff, Phishing Rod is a Laravel API backend named `phishing-rod` connected to a PostgreSQL database named `phishing_rod`. The first application table, `scans`, has been created and the backend can accept a URL through `POST /api/scans`, create a scan row with a UUID and `queued` status, and return a JSON response. The project direction is API-first with no Blade frontend and no authentication for the MVP. A missing model import bug in `Api\ScanController` was fixed by importing `App\Models\Scan`. urlscan.io integration, queue processing, artifact storage, the Python FastAPI ML service, feature extraction, and real `.joblib` model inference are still pending. The active ML runtime should eventually use exactly three models: `best_combined_model.joblib`, `best_html_enhanced_model.joblib`, and `best_url_model.joblib`; the older `best_html_model.joblib` is deprecated and should not be used as an active runtime model.
