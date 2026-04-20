<?php

declare(strict_types=1);

// Load environment variables from .env if running locally (not on Railway)
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value]  = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}

require_once __DIR__ . '/vendor/autoload.php';

use RAG\Router;

$router = new Router();
$router->dispatch();
