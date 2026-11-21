<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', static function (Request $request) {
    $actor = $request->user('sanctum') ?? $request->user();
    if ($actor && (bool) $actor->is_active && method_exists($actor, 'hasRole') && $actor->hasRole('admin')) {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'core_version' => config('lms.core_version'),
            'php' => PHP_VERSION,
        ])->header('Cache-Control', 'private, no-store');
    }
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ])->header('Cache-Control', 'no-store');
});
