<?php

return [
    // Settings → API keys in eContract. cl_test_… for development, cl_live_… in production.
    'api_key' => env('ECONTRACT_API_KEY'),
    'base_url' => env('ECONTRACT_BASE_URL', 'https://api.econtract.online'),

    // Returned once by POST /webhooks (or shown in Settings → Webhooks when you create the endpoint)
    'webhook_secret' => env('ECONTRACT_WEBHOOK_SECRET'),
    // Reject signatures older than this (replay protection)
    'webhook_tolerance' => (int) env('ECONTRACT_WEBHOOK_TOLERANCE', 300),
    'webhook_path' => env('ECONTRACT_WEBHOOK_PATH', 'webhooks/econtract'),

    // Where signed PDFs are stored
    'disk' => env('ECONTRACT_DISK', 'local'),
    'directory' => env('ECONTRACT_DIRECTORY', 'signed-contracts'),

    'timeout' => 30,
    'retries' => 2,
];
