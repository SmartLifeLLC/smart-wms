<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Keys
    |--------------------------------------------------------------------------
    |
    | Valid API keys for accessing the WMS API.
    | Keys can be comma-separated in the environment variable.
    |
    */
    'keys' => array_filter(
        array_map('trim', explode(',', env('API_KEYS', '')))
    ),
];
