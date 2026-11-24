<?php

return [
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
    'webhook_tolerance' => 300,
    'admin_email' => env('ADMIN_EMAIL'),
    'sizes' => ['1024x1024', '1536x1024', '1024x1536'],
    'qualities' => ['low', 'medium', 'high'],
    // The Pixelforge quota is shared by every key on the account (500 generations per UTC day, 3 concurrent),
    // so a public app caps its own usage to keep strangers from exhausting a reviewer's quota.
    'limits' => [
        'per_user_daily' => (int) env('LIMIT_USER_DAILY', 15),
        'per_user_in_flight' => (int) env('LIMIT_USER_IN_FLIGHT', 2),
        'global_daily' => (int) env('LIMIT_GLOBAL_DAILY', 250),
    ],
];
