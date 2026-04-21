if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;

        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[$key] = trim($value);
            putenv("$key=$value");
        }
    }
}

/**
 * IMPORTANT: Railway injects env vars into $_SERVER, not $_ENV
 */
foreach ($_SERVER as $key => $value) {
    if (is_string($value) && (
        str_starts_with($key, 'NEON_') ||
        str_starts_with($key, 'COHERE_') ||
        str_starts_with($key, 'ALLOWED_')
    )) {
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}