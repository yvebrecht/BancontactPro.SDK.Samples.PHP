<?php

declare(strict_types=1);

// Router script for PHP's built-in server: `php -S localhost:5087 -t public public/index.php`.

use SampleWeb\App;

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// app.js and app.css are served by the built-in server itself.
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

require __DIR__ . '/../vendor/autoload.php';

// Read .env, if there is one, without overriding what the environment already sets.
$env = __DIR__ . '/../.env';
if (is_file($env)) {
    foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line[0] !== '#' && str_contains($line, '=')) {
            [$name, $value] = explode('=', $line, 2);
            if (getenv(trim($name)) === false) {
                putenv(trim($name) . '=' . trim($value));
            }
        }
    }
}

(new App(__DIR__ . '/../var'))->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
