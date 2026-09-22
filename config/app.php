<?php

$timezone = trim((string) ($_ENV['APP_TIMEZONE'] ?? 'Asia/Taipei'));
try {
    $timezone = (new \DateTimeZone($timezone))->getName();
} catch (\Throwable) {
    // Fail to the historical application contract, never to php.ini/process order.
    $timezone = 'Asia/Taipei';
}

return [
    'env'   => $_ENV['APP_ENV'] ?? 'production',
    'debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
    'key'   => $_ENV['APP_KEY'] ?? '',
    'url'   => $_ENV['APP_URL'] ?? 'http://localhost:8080',
    'timezone' => $timezone,
    'locale'   => 'zh_TW',
];
