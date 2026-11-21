<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\Scorm\Http\Controllers\ScormRuntimeController;

Route::options('/api/scorm-runtime/commit', [ScormRuntimeController::class, 'preflight']);
Route::post('/api/scorm-runtime/commit', [ScormRuntimeController::class, 'commit'])->middleware(['api', 'throttle:120,1']);
