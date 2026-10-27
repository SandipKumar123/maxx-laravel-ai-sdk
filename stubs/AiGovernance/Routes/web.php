<?php

use Illuminate\Support\Facades\Route;
use Modules\AiGovernance\App\Http\Controllers\AiGovernanceController;

Route::middleware(['web', 'auth', 'regional.scope'])->prefix('ai-governance')->name('ai-governance.')->group(function () {
    Route::get('/executions/{requestId}', function (string $requestId) {
        return response()->json(app(\Modules\AiGovernance\Services\HrmsAiClient::class)->status($requestId));
    })->whereUuid('requestId')->middleware('throttle:60,1')->name('execution-status');
    Route::get('/', [AiGovernanceController::class, 'index'])->name('index');
    Route::get('/analytics', [AiGovernanceController::class, 'analytics'])->name('analytics');
    Route::get('/requests', [AiGovernanceController::class, 'requests'])->name('requests');
    Route::get('/sdk-connection', [AiGovernanceController::class, 'sdk'])->name('sdk');
    Route::post('/connection-check', [AiGovernanceController::class, 'checkConnection'])
        ->middleware('throttle:6,1')->name('connection-check');
    Route::post('/sdk-connection', [AiGovernanceController::class, 'saveConnection'])
        ->middleware('throttle:6,1')->name('connection-save');
});
