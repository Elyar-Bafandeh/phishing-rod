<?php

return [

    /*
    |--------------------------------------------------------------------------
    | urlscan.io Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the urlscan.io API, which is used as the safe retrieval
    | layer for submitted URLs. The application never browses submitted URLs
    | directly — urlscan.io fetches the page HTML/DOM on our behalf.
    |
    | The API key is intentionally kept out of this file and read from the
    | environment so it is never committed to version control.
    |
    */

    'base_url' => env('URLSCAN_BASE_URL', 'https://urlscan.io'),

    'api_key' => env('URLSCAN_API_KEY'),

    'visibility' => env('URLSCAN_VISIBILITY', 'unlisted'),

    'timeout' => (int) env('URLSCAN_TIMEOUT', 30),

];
