<?php

use Illuminate\Support\Facades\Route;
use Modules\AiGovernance\App\Http\Controllers\Api\AiGovernanceApiController;
use Modules\AiGovernance\App\Http\Middleware\ApiResponse;
use Modules\AiGovernance\App\Http\Middleware\RequireBearerToken;

Route::middleware([ApiResponse::class, 'api', 'auth:sanctum', RequireBearerToken::class, 'regional.scope', 'throttle:60,1,ai-governance-api:'])
    ->prefix('api/ai-governance')->name('api.ai-governance.')->group(function () {
        Route::get('/overview', [AiGovernanceApiController::class, 'overview'])->name('overview');
        Route::get('/analytics', [AiGovernanceApiController::class, 'analytics'])->name('analytics');
        Route::get('/requests', [AiGovernanceApiController::class, 'requests'])->name('requests');
        Route::get('/sdk-connection', [AiGovernanceApiController::class, 'connection'])->name('sdk');
        Route::post('/connection-check', [AiGovernanceApiController::class, 'checkConnection'])
            ->middleware('throttle:6,1,ai-governance-connection:')->name('connection-check');
        Route::put('/sdk-connection', [AiGovernanceApiController::class, 'saveConnection'])
            ->middleware('throttle:6,1,ai-governance-connection:')->name('connection-save');
        Route::get('/executions/{requestId}', [AiGovernanceApiController::class, 'execution'])
            ->whereUuid('requestId')->name('execution-status');
    });
