<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'core_version' => config('lms.core_version'),
    'php' => PHP_VERSION,
]));
