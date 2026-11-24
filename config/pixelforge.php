<?php

return [
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
    'webhook_tolerance' => 300,
    'admin_email' => env('ADMIN_EMAIL'),
    'sizes' => ['1024x1024', '1536x1024', '1024x1536'],
    'qualities' => ['low', 'medium', 'high'],
    // Fallback pricing used only if the (unauthenticated) /pricing endpoint is unreachable and nothing is cached.
];
