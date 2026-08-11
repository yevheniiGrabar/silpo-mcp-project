<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'Розумний кошик — Silpo BFF',
    'docs' => '/health',
]));

/*
| Lightweight heartbeat used by CI, uptime checks and the demo harness.
| The real BFF API (/api/v1/*) is added in phase B6 per docs/01 §6.
*/
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]));
