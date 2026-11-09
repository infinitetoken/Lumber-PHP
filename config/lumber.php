<?php

declare(strict_types=1);

// Lumber's settings for Laravel. Set them in .env; `php artisan vendor:publish --tag=lumber-config` copies this file to
// config/lumber.php if you'd rather set them here.

return [
    // The API key from your app's Secrets screen in Lumber. Only its token is sent to Lumber; the rest is the key that
    // encrypts every log, and it never leaves your server.
    'api_key' => env('LUMBER_API_KEY'),

    // Sent with every log as `env` in its context: Laravel's environment (APP_ENV) unless set. Set it empty to send none.
    'environment' => env('LUMBER_ENVIRONMENT'),

    // The lowest level the `lumber` log channel sends: debug, info, notice, warning, error, critical, alert or emergency.
    'level' => env('LUMBER_LEVEL', 'debug'),

    // Send each log as it's logged, waiting for Lumber to answer, rather than as the request or job ends. For serverless
    // hosts (Laravel Vapor, Bref) that freeze the process once the response is sent.
    'sync' => env('LUMBER_SYNC', false),

    // Send the process id and hostname (and the environment) with every log.
    'auto_context' => env('LUMBER_AUTO_CONTEXT', true),

    // Seconds to wait for Lumber to answer one log, and that sending may take as a request or job ends.
    'timeout' => env('LUMBER_TIMEOUT', 10),
    'flush_timeout' => env('LUMBER_FLUSH_TIMEOUT', 5),

    // Logs held before they're sent: when it's full, a new log sends them there and then.
    'max_buffer' => env('LUMBER_MAX_BUFFER', 1000),

    // The Lumber API, for testing against another server.
    'endpoint' => env('LUMBER_ENDPOINT', 'https://api.lumber.infinitetoken.com'),
];
