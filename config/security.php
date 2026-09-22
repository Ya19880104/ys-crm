<?php

return [
    'csrf_token_lifetime' => 3600, // 秒
    'turnstile' => [
        'site_key'   => $_ENV['TURNSTILE_SITE_KEY'] ?? '',
        'secret_key' => $_ENV['TURNSTILE_SECRET_KEY'] ?? '',
    ],
    'rate_limit' => [
        'login'  => ['max' => 5, 'window' => 900],   // 5 次 / 15 分鐘
        'wish'   => ['max' => 3, 'window' => 600],   // 3 次 / 10 分鐘
    ],
];
