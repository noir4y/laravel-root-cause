<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response(<<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel Root Cause Demo</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; background: #0f172a; color: #e2e8f0; }
        main { max-width: 960px; margin: 0 auto; padding: 48px 24px; }
        h1 { font-size: clamp(2.5rem, 8vw, 5rem); margin: 0 0 16px; }
        p, li { line-height: 1.6; color: #cbd5e1; }
        code, pre { background: #111827; color: #f8fafc; border-radius: 10px; }
        pre { padding: 16px; overflow: auto; }
        a { color: #7dd3fc; }
    </style>
</head>
<body>
<main>
    <h1>Laravel Root Cause Demo</h1>
    <p>This demo app exposes repeatable incidents for validation failures, unhandled exceptions, duplicate query bursts, and N+1 query pathologies.</p>
    <ul>
        <li><a href="/api/demo/validation-failure">Validation failure</a> (GET and POST both return a JSON 422)</li>
        <li><a href="/api/demo/unhandled-exception">Unhandled exception</a></li>
        <li><a href="/api/demo/duplicate-query-burst">Duplicate query burst</a></li>
        <li><a href="/api/demo/n-plus-one">N+1 query pathology</a></li>
    </ul>
    <pre>composer install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate --seed
php artisan serve</pre>
</main>
</body>
</html>
HTML
    );
});
