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
    | Model selection and the two-model weighted fusion (URL model +
    | enhanced-HTML model) live entirely inside the Python service, so Laravel
    | only needs connection settings here — it does not choose a model.
    |
    */

    'base_url' => env('ML_SERVICE_URL', 'http://127.0.0.1:9000'),

    'token' => env('ML_SERVICE_TOKEN'),

    'timeout' => (int) env('ML_SERVICE_TIMEOUT', 30),

];
