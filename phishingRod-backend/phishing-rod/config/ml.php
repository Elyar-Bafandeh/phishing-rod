<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Python ML Service Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the internal Python/FastAPI ML service that performs
    | feature extraction and model inference. This service is internal only
    | and is protected by a bearer token — it must never be publicly exposed.
    |
    | The active model defaults to the primary combined model. Only the three
    | active runtime models may be used; the deprecated basic HTML model
    | (best_html_model.joblib) must never be selected here.
    |
    */

    'base_url' => env('ML_SERVICE_URL', 'http://127.0.0.1:9000'),

    'token' => env('ML_SERVICE_TOKEN'),

    'timeout' => (int) env('ML_SERVICE_TIMEOUT', 30),

    'active_model' => env('ML_ACTIVE_MODEL', 'best_combined_model.joblib'),

];
