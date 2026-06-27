# Phishing Rod Security Requirements

**Document purpose:** This Markdown file complements the other Phishing Rod project documents:

- `phishing_rod_project_brief.md`
- `phishing_rod_backend_phased_plan.md`
- `phishing_rod_current_progress_handoff.md`

The other documents explain the project goal, backend phases, ML service, and current progress. This document defines the **mandatory security requirements** that every human developer or AI coding agent must follow while implementing Phishing Rod.

**Project name:** Phishing Rod  
**Backend:** Laravel API backend  
**Database:** PostgreSQL  
**ML runtime:** Separate internal Python/FastAPI service  
**External scan provider:** urlscan.io API  
**Authentication status:** No user login for MVP  
**Active ML models:** 3 runtime models only

```text
best_combined_model.joblib
best_html_enhanced_model.joblib
best_url_model.joblib
```

The older/basic `best_html_model.joblib` is not an active runtime model. It should not be copied into the production `ml-service/models/` directory, exposed through the API, or used in model-selection logic.

---

## 1. Security Philosophy

Phishing Rod receives arbitrary URLs from users. Some submitted URLs may be phishing pages, malware delivery pages, credential theft pages, or intentionally malicious test inputs. Therefore, the system must assume that **all user-submitted URLs and all retrieved HTML are untrusted**.

The project must be designed so that:

1. Laravel never directly browses or renders submitted websites.
2. Potentially malicious website content is fetched through urlscan.io, not through the host machine.
3. Python parses HTML statically as text/markup only.
4. JavaScript from submitted websites is never executed by Laravel or the Python service.
5. Raw DOM/HTML artifacts are not exposed through public API responses.
6. API keys and internal tokens never appear in source code, Git history, logs, frontend responses, screenshots, or generated documentation.
7. The Python ML service is internal-only and cannot be accessed directly by public users.
8. `.joblib` model files are treated as trusted binary artifacts and are never loaded from user uploads or untrusted sources.

Any implementation that violates these principles must be rejected or reverted.

---

## 2. Mandatory Security Rules for AI Coding Tools

Any AI coding tool working on this project must follow these rules exactly.

### SEC-AI-001 — Do not invent insecure shortcuts

Do not replace urlscan.io with direct `requests.get()`, Laravel HTTP browsing, Selenium, Playwright, Puppeteer, cURL, browser automation, or any local rendering of user-submitted websites unless explicitly approved in a future architecture change.

Correct flow:

```text
User URL -> Laravel -> urlscan.io -> DOM/result artifacts -> Python static feature extraction -> ML prediction
```

Incorrect flow:

```text
User URL -> Laravel directly downloads page
User URL -> Python directly downloads page
User URL -> local browser renders page
```

### SEC-AI-002 — Do not hard-code secrets

Never write real secrets into source files, Markdown files, examples, tests, or committed configuration.

Forbidden examples:

```php
'api_key' => 'real-urlscan-api-key-here'
```

```python
TOKEN = 'real-internal-token-here'
```

```md
URLSCAN_API_KEY=actual_live_key
```

Only placeholders are allowed:

```env
URLSCAN_API_KEY=your_urlscan_api_key_here
ML_SERVICE_TOKEN=dev-internal-token-change-me
```

### SEC-AI-003 — Do not expose Python service publicly

The Python FastAPI service must be reachable only from Laravel or from local development tools. It must not be exposed as a public internet endpoint.

Public users should only access Laravel API endpoints such as:

```text
POST /api/scans
GET /api/scans/{uuid}
```

They must never directly access:

```text
POST /predict
```

### SEC-AI-004 — Do not expose raw artifacts publicly

Do not return full raw HTML, full DOM, full urlscan result JSON, screenshots, response bodies, or downloaded artifacts through public API responses by default.

Public API responses may include:

```text
uuid
submitted_url
normalized_url
domain
status
verdict
confidence
model_name
created_at
completed_at
high-level error_message
```

Public API responses must not include:

```text
full DOM HTML
full urlscan result JSON
urlscan API key
internal service token
absolute server file paths
raw stack traces
full feature vectors unless explicitly approved
```

### SEC-AI-005 — Keep model count consistent

The runtime system has exactly 3 active model files:

```text
best_combined_model.joblib
best_html_enhanced_model.joblib
best_url_model.joblib
```

Do not reintroduce `best_html_model.joblib` as an active model. The enhanced HTML model replaces the older initial HTML model.

---

## 3. Threat Model

The system must defend against the following categories of risk.

### 3.1 User-submitted URL abuse

Attackers may submit URLs designed to:

- Trigger SSRF-like behavior.
- Target localhost or private network addresses.
- Abuse urlscan.io quotas.
- Create excessive queue load.
- Store extremely long or malformed inputs.
- Submit URLs containing private tokens or sensitive query parameters.

### 3.2 API key and token exposure

Sensitive secrets include:

```text
APP_KEY
DB_PASSWORD
URLSCAN_API_KEY
ML_SERVICE_TOKEN
future user auth secrets
future API keys
future cloud storage credentials
```

These must never leak through:

```text
Git commits
.env files committed by mistake
Laravel error pages
application logs
Postman screenshots
API responses
frontend JavaScript
Markdown documentation
AI chat prompts
crash reports
```

### 3.3 Malicious HTML/content exposure

Retrieved DOM/HTML may contain:

- Credential theft forms.
- Obfuscated JavaScript.
- Phishing-kit comments.
- External asset references.
- Malicious redirects.
- Fake validation text.
- Tracking links.

The backend must treat stored DOM/HTML as dangerous untrusted data.

### 3.4 Python model loading risks

`.joblib` files can execute code during loading if they are malicious because they are pickle-based serialized objects. Therefore, the Python service must only load trusted model files that were produced by the project owner or trusted training pipeline.

Never allow users to upload `.joblib`, `.pkl`, `.pickle`, or model files for runtime loading.

### 3.5 Public anonymous API abuse

Because the MVP has no login, an attacker may attempt to:

- Spam scan submissions.
- Fill the database with junk records.
- Exhaust queue workers.
- Exhaust urlscan.io quotas.
- Trigger high disk usage through artifacts.
- Enumerate scan UUIDs.

The backend must include basic abuse prevention before deployment.

---

## 4. Secrets and Environment Configuration

### SEC-ENV-001 — Use `.env` for secrets

All secrets must be stored in `.env` locally and in secure environment variables in production.

Required secret-related `.env` values:

```env
APP_KEY=
DB_PASSWORD=
URLSCAN_API_KEY=
ML_SERVICE_TOKEN=
```

Service configuration values:

```env
URLSCAN_BASE_URL=https://urlscan.io
URLSCAN_VISIBILITY=unlisted
URLSCAN_TIMEOUT=30

ML_SERVICE_URL=http://127.0.0.1:9000
ML_SERVICE_TIMEOUT=30
ML_ACTIVE_MODEL=best_combined_model.joblib
```

### SEC-ENV-002 — Maintain `.env.example` safely

`.env.example` must exist and contain placeholders only.

Allowed:

```env
URLSCAN_API_KEY=
ML_SERVICE_TOKEN=dev-internal-token-change-me
```

Forbidden:

```env
URLSCAN_API_KEY=real_key_value
DB_PASSWORD=real_password
```

### SEC-ENV-003 — `.gitignore` must protect secrets and artifacts

The repository must ignore:

```gitignore
.env
.env.*
!.env.example
/storage/app/scans/
/storage/logs/*.log
*.log
```

For the Python service, ignore:

```gitignore
ml-service/.env
ml-service/venv/
ml-service/__pycache__/
ml-service/**/*.pyc
```

Whether model files are committed depends on project choice. If the models are too large or private, ignore them:

```gitignore
ml-service/models/*.joblib
ml-service/models/*.pkl
ml-service/models/*.pickle
```

If model files are committed for thesis reproducibility, they must be trusted project-generated artifacts and should have checksums documented.

### SEC-ENV-004 — Rotate leaked secrets immediately

If any real secret is accidentally committed, pasted into chat, included in a screenshot, or exposed in a log, it must be considered compromised.

Required response:

1. Revoke or rotate the leaked secret.
2. Remove it from the working tree.
3. Remove it from Git history if necessary.
4. Update `.env` locally with the new value.
5. Verify the old secret no longer works.

---

## 5. Laravel API Security Requirements

### SEC-API-001 — API-first only for MVP

The MVP should use:

```text
routes/api.php
API controllers
FormRequest validation
JSON resources
Postman/cURL tests
```

Do not add Blade pages or browser forms unless explicitly requested later.

### SEC-API-002 — Return JSON errors to API clients

Postman and frontend clients should send:

```http
Accept: application/json
Content-Type: application/json
```

The API should avoid returning Laravel debug HTML pages in normal API usage.

Before deployment:

```env
APP_DEBUG=false
APP_ENV=production
```

### SEC-API-003 — Do not expose stack traces in production

Production API errors must not include:

```text
stack traces
file paths
environment variables
database credentials
API keys
internal tokens
raw exception dumps
```

Instead, return controlled responses:

```json
{
  "message": "Scan processing failed. Please try again later."
}
```

Detailed errors may be logged server-side, but logs must also avoid secrets.

### SEC-API-004 — Use UUIDs as public identifiers

Public API endpoints must use scan UUIDs, not auto-increment database IDs.

Correct:

```text
GET /api/scans/{uuid}
```

Avoid public endpoints like:

```text
GET /api/scans/1
```

### SEC-API-005 — Avoid scan enumeration

UUIDs reduce enumeration risk, but the system should still not expose unnecessary listing data.

For anonymous MVP:

- `GET /api/scans/{uuid}` is acceptable.
- `GET /api/scans` should be limited or development-only unless there is a clear reason to expose recent public scans.
- If `GET /api/scans` remains public, it must not expose raw artifacts or sensitive metadata.

---

## 6. URL Validation and SSRF Prevention

Even though urlscan.io performs the actual page retrieval, Laravel must still reject dangerous or unsupported inputs.

### SEC-URL-001 — Allowed schemes

Only these schemes are allowed:

```text
http://
https://
```

Reject:

```text
file://
ftp://
gopher://
ldap://
ssh://
smb://
javascript:
data:
chrome://
about:
```

### SEC-URL-002 — Block localhost and private/internal hosts

Reject URLs whose host is:

```text
localhost
localhost.localdomain
127.0.0.1
0.0.0.0
::1
```

Reject private, loopback, link-local, multicast, and reserved IP ranges, including at minimum:

```text
10.0.0.0/8
172.16.0.0/12
192.168.0.0/16
127.0.0.0/8
169.254.0.0/16
::1/128
fc00::/7
fe80::/10
```

### SEC-URL-003 — Validate after normalization

The validation flow should be:

```text
raw input -> trim -> parse -> validate scheme -> validate host -> normalize -> store
```

Do not validate only the raw string and then later transform it into something different.

### SEC-URL-004 — Limit URL length

Maximum URL length for MVP:

```text
2048 characters
```

Longer input should return HTTP `422`.

### SEC-URL-005 — Do not over-normalize query strings

Do not remove query strings unless there is a clear privacy feature implemented. Query strings can affect the actual page content and scan result.

However, be careful about displaying query strings publicly because they may contain tokens or email addresses.

Future improvement:

- Store full submitted URL internally.
- Display redacted URL publicly if needed.

---

## 7. urlscan.io Integration Security

### SEC-URLSCAN-001 — Use urlscan.io as the safe retrieval layer

Laravel and Python must not directly browse submitted websites. urlscan.io is responsible for safely retrieving DOM/HTML and scan metadata.

### SEC-URLSCAN-002 — Store urlscan API key only in environment

The key must be accessed through:

```php
config('urlscan.api_key')
```

Never hard-code the API key.

### SEC-URLSCAN-003 — Send API key only to urlscan.io

The `api-key` header must only be sent to the configured urlscan.io base URL.

Never forward the urlscan API key to:

```text
Python ML service
frontend
user-submitted URLs
logs
third-party services other than urlscan.io
```

### SEC-URLSCAN-004 — Use unlisted visibility by default

Default scan visibility:

```text
unlisted
```

This reduces accidental public exposure of URLs that may contain sensitive query parameters.

`.env`:

```env
URLSCAN_VISIBILITY=unlisted
```

### SEC-URLSCAN-005 — Handle rate limits safely

If urlscan.io returns HTTP `429`, the backend should:

1. Avoid immediate repeated retries.
2. Use queue backoff.
3. Mark the scan as waiting or failed depending on retry exhaustion.
4. Not expose the API key or full provider error publicly.

### SEC-URLSCAN-006 — Store provider errors carefully

Internal logs may contain enough detail for debugging. Public `error_message` should be high-level.

Good public message:

```text
urlscan.io scan could not be completed at this time.
```

Bad public message:

```text
Full provider response with headers, API key, stack trace, and request body.
```

---

## 8. Queue and Job Security

### SEC-JOB-001 — Long work must be asynchronous

Do not run urlscan.io submission, polling, DOM download, or ML inference inside the original `POST /api/scans` request.

Correct:

```text
POST /api/scans -> create row -> dispatch job -> return immediately
```

### SEC-JOB-002 — Jobs must fail safely

On job failure:

1. Set scan status to `failed`.
2. Store a safe high-level `error_message`.
3. Log the exception server-side.
4. Do not leave scans permanently stuck in ambiguous states if retries are exhausted.

### SEC-JOB-003 — Use retry limits and backoff

Jobs that call external services must have:

```text
maximum attempts
backoff delays
timeouts
controlled failure behavior
```

### SEC-JOB-004 — Do not log raw DOM by default

Queue workers must not log full DOM/HTML content. Logs may include:

```text
scan UUID
scan ID
status
provider status code
high-level failure reason
```

Logs must not include:

```text
full DOM HTML
full urlscan result JSON
API keys
internal tokens
large payloads
```

---

## 9. Artifact Storage Security

### SEC-ART-001 — Store raw artifacts outside public web root

Store scan artifacts under Laravel storage, not under `public/`.

Correct:

```text
storage/app/scans/{scan_uuid}/result.json
storage/app/scans/{scan_uuid}/dom.html
```

Incorrect:

```text
public/scans/{scan_uuid}/dom.html
```

### SEC-ART-002 — Do not serve artifacts directly

Do not create public routes that directly return stored DOM/HTML or full result JSON unless explicitly protected and approved.

### SEC-ART-003 — Enforce size limits

The system must enforce reasonable maximum sizes for stored artifacts.

Suggested MVP limits:

```text
DOM HTML max size: 5 MB
urlscan result JSON max size: 10 MB
```

If exceeded:

1. Store a controlled error or truncated artifact if intentionally supported.
2. Do not crash the queue worker.
3. Do not store unbounded content.

### SEC-ART-004 — Track checksums

For stored artifacts, save SHA-256 checksums where possible:

```text
sha256
size_bytes
content_type
```

This supports reproducibility and integrity checks.

### SEC-ART-005 — Treat stored HTML as untrusted forever

Even after storage, DOM/HTML remains untrusted. Do not render it in an admin panel or browser without escaping/sandboxing.

---

## 10. Python ML Service Security

### SEC-PY-001 — Internal-only service

The Python service must listen only on localhost or a private Docker/network interface in normal deployment.

Local development:

```text
http://127.0.0.1:9000
```

Docker internal example:

```text
http://ml-service:9000
```

Do not expose it publicly.

### SEC-PY-002 — Require internal bearer token

Laravel must call Python with:

```http
Authorization: Bearer {ML_SERVICE_TOKEN}
```

Python must reject missing or incorrect tokens with HTTP `401` or `403`.

### SEC-PY-003 — Python must not fetch submitted URLs

The Python service receives:

```text
url
dom_html
optional urlscan_result
model_name
```

It must not perform network requests to the submitted URL.

### SEC-PY-004 — Static HTML parsing only

HTML feature extraction must use static parsing only, such as BeautifulSoup.

Allowed:

```text
BeautifulSoup parsing
regex/text analysis
URL/domain parsing
feature counting
```

Forbidden:

```text
executing JavaScript
rendering the page in a browser
loading external scripts/images/stylesheets
submitting forms
clicking links
following redirects from HTML
```

### SEC-PY-005 — Model files must be trusted

Only load known trusted model files from the local `ml-service/models/` directory.

Active model allowlist:

```text
best_combined_model.joblib
best_html_enhanced_model.joblib
best_url_model.joblib
```

Reject any `model_name` not in the allowlist.

### SEC-PY-006 — Never load user-provided model files

Do not add an endpoint or feature that lets users upload `.joblib`, `.pkl`, `.pickle`, or model files for loading.

### SEC-PY-007 — Validate request size

The Python service should reject excessively large requests.

Suggested limits:

```text
dom_html: 5 MB
urlscan_result JSON: 10 MB
```

### SEC-PY-008 — Return controlled model errors

If model loading or prediction fails, return a controlled error to Laravel. Do not return Python stack traces or server paths in public-facing output.

---

## 11. Model and Feature Schema Security

### SEC-MODEL-001 — Preserve feature schema exactly

Prediction-time features must match training-time features exactly:

```text
same feature names
same feature order
same default values
same preprocessing
same model expectations
```

Incorrect feature order can produce dangerous false confidence.

### SEC-MODEL-002 — Use schema files

Each active model should have a schema file:

```text
ml-service/models/feature_schemas/url_feature_schema.json
ml-service/models/feature_schemas/html_enhanced_feature_schema.json
ml-service/models/feature_schemas/combined_feature_schema.json
```

### SEC-MODEL-003 — Reject unknown model names

The `/predict` endpoint must reject any model name that is not in the active allowlist.

Allowed:

```text
best_combined_model.joblib
best_html_enhanced_model.joblib
best_url_model.joblib
```

Rejected:

```text
../../../some-file.joblib
best_html_model.joblib
uploaded_model.pkl
any arbitrary filename
```

### SEC-MODEL-004 — Do not expose full feature vectors publicly by default

Feature vectors can be useful for debugging and thesis analysis, but they may reveal internal model logic. Store them internally in `feature_sets`, but do not expose full vectors publicly unless explicitly approved.

---

## 12. Database Security

### SEC-DB-001 — Use least privilege database credentials

For production, the application database user should only have required permissions for the application database. It should not be a PostgreSQL superuser.

Local development may use `postgres`, but production should not.

### SEC-DB-002 — Do not store secrets in database unless necessary

Do not store:

```text
URLSCAN_API_KEY
ML_SERVICE_TOKEN
DB_PASSWORD
APP_KEY
```

### SEC-DB-003 — Store minimal user/client metadata

For MVP, avoid storing raw IP addresses unless necessary. If abuse tracking is needed, prefer:

```text
source_ip_hash
```

### SEC-DB-004 — Use migrations only

Do not manually create production tables in pgAdmin. Use Laravel migrations so the schema is reproducible.

---

## 13. Logging and Error Handling

### SEC-LOG-001 — Never log secrets

Logs must not include:

```text
URLSCAN_API_KEY
ML_SERVICE_TOKEN
DB_PASSWORD
APP_KEY
Authorization headers
full request headers from external services
```

### SEC-LOG-002 — Avoid logging full user-submitted URLs when possible

URLs can contain sensitive tokens, emails, or session identifiers. Prefer logging:

```text
scan UUID
domain
status
provider status code
```

If full URLs are logged in local development, avoid copying logs into GitHub issues, chat prompts, or screenshots.

### SEC-LOG-003 — Use safe public error messages

Public API `error_message` should be short and safe.

Good:

```text
Prediction service unavailable.
```

Bad:

```text
Connection failed to http://127.0.0.1:9000 with token abc123 and stack trace...
```

---

## 14. CORS and Frontend Exposure

### SEC-CORS-001 — Keep CORS restrictive

When a frontend is added, CORS should allow only known frontend origins.

Local development example:

```text
http://localhost:5173
http://127.0.0.1:5173
```

Do not use wildcard CORS in production:

```text
Access-Control-Allow-Origin: *
```

unless the endpoint is intentionally public and does not expose sensitive data.

### SEC-CORS-002 — Never expose secrets to frontend

The frontend must never receive:

```text
URLSCAN_API_KEY
ML_SERVICE_TOKEN
internal service URLs
storage paths
server filesystem paths
```

---

## 15. Rate Limiting and Abuse Prevention

### SEC-RATE-001 — Rate-limit anonymous scan creation

Because there is no login in the MVP, apply rate limiting to:

```text
POST /api/scans
```

Suggested local/demo default:

```text
5 scan submissions per minute per IP
```

Adjust later based on deployment needs.

### SEC-RATE-002 — Do not rate-limit status polling too aggressively

Clients need to poll:

```text
GET /api/scans/{uuid}
```

Use a more generous limit for polling than for creation.

### SEC-RATE-003 — Add queue protections

Queue jobs should have:

```text
attempt limits
backoff
failure handling
reasonable timeouts
```

This prevents unbounded retries from exhausting resources.

---

## 16. Dependency and Supply Chain Security

### SEC-DEP-001 — Keep dependency manifests committed

Commit:

```text
composer.json
composer.lock
requirements.txt
```

If Python uses Poetry or pip-tools later, commit the relevant lock file.

### SEC-DEP-002 — Do not install unknown packages casually

Do not add packages unless they are necessary and reputable.

Every new dependency should have a clear purpose.

### SEC-DEP-003 — Separate dev and production dependencies

Do not install debugging or development-only tools in production containers/environments unless needed.

### SEC-DEP-004 — Pin Python dependencies where possible

The Python service should prefer pinned or controlled versions to reduce reproducibility issues.

---

## 17. Development and Git Hygiene

### SEC-GIT-001 — Check status before commits

Before committing, run:

```bash
git status
```

Confirm no secret or artifact files are staged.

### SEC-GIT-002 — Never commit `.env`

If `.env` is staged, unstage it immediately:

```bash
git restore --staged .env
```

### SEC-GIT-003 — Do not commit scan artifacts by accident

Do not commit:

```text
storage/app/scans/
storage/logs/
large DOM files
urlscan raw responses
```

### SEC-GIT-004 — Treat screenshots carefully

Screenshots may accidentally show:

```text
API keys
.env values
Postman Authorization headers
database passwords
private URLs
```

Review screenshots before sharing or committing.

---

## 18. Testing Requirements for Security

Security behavior must be tested, not only described.

### Required tests

Create tests for:

```text
invalid URL rejected
unsupported schemes rejected
localhost rejected
private IP rejected
missing URL returns 422
too long URL returns 422
rate limit returns 429
API response does not expose internal ID as primary identifier
API response does not expose raw DOM
urlscan client sends api-key header only to urlscan client
ML client sends bearer token
ML endpoint rejects missing/invalid token
Python rejects unknown model_name
Python does not accept deprecated best_html_model.joblib
```

### Testing rule

Tests must not call real urlscan.io or a real Python service unless explicitly marked as integration tests.

Use HTTP fakes/mocks for Laravel service client tests.

---

## 19. Deployment Security Checklist

Before any public deployment, all of the following must be true:

```text
APP_ENV=production
APP_DEBUG=false
APP_KEY generated and secret
.env not committed
URLSCAN_API_KEY stored only in environment
ML_SERVICE_TOKEN stored only in environment
Python service not publicly reachable
Laravel can reach Python internally
PostgreSQL is not publicly exposed without protection
Database user is not superuser
Rate limiting enabled
Private/internal URL validation enabled
Queue worker running under controlled process manager
Logs do not contain secrets
Raw artifacts are outside public web root
CORS restricted to known frontend origin if frontend exists
HTTPS enabled at the public edge
```

---

## 20. Security Completion Definition

The project meets MVP security requirements when:

```text
User-submitted URLs are validated and dangerous schemes/hosts are rejected.
Laravel never directly browses submitted websites.
urlscan.io API key is stored only in environment variables.
urlscan.io scans default to unlisted visibility.
Long scan work happens through queue jobs.
Raw DOM and result JSON are stored outside public web root.
Raw artifacts are not returned in public API responses.
Python ML service is internal-only and protected with bearer token.
Python performs static feature extraction only.
Only 3 active trusted model files are loadable.
Unknown or deprecated model names are rejected.
Secrets are not committed, logged, or exposed in responses.
Anonymous scan creation is rate-limited.
Production debug mode is disabled.
Security tests cover validation, token use, rate limits, and artifact exposure.
```

---

## 21. Final Instruction to Future Developers and AI Tools

When implementing Phishing Rod, security must not be treated as a later cleanup step. The application is specifically designed to process suspicious and potentially malicious websites. Every component must assume hostile input.

If there is a conflict between fast implementation and these security requirements, follow this document first.

If a requested code change would expose secrets, directly browse user-submitted URLs, publicly expose the Python service, load untrusted models, or serve raw malicious HTML, do not implement it without explicit human approval and an updated architecture document.
