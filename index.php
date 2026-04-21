<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

/**
 * Load .env file for local development
 */
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);

            $key   = trim($key);
            $value = trim($value);

            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

/**
 * Railway fix: move env vars from $_SERVER → $_ENV
 */
foreach ($_SERVER as $key => $value) {
    if (is_string($value) && (
        strpos($key, 'NEON_') === 0 ||
        strpos($key, 'COHERE_') === 0 ||
        strpos($key, 'ALLOWED_') === 0
    )) {
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

require_once __DIR__ . '/vendor/autoload.php';

use RAG\Router;

$router = new Router();
$router->dispatch();