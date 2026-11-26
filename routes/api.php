<?php

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\XenditWebhookController;
use App\Http\Middleware\AuthenticateApiClient;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/payments', [PaymentController::class, 'store'])
        ->middleware([AuthenticateApiClient::class, 'throttle:payment-create']);

    Route::get('/payments/{transactionNumber}', [PaymentController::class, 'show'])
        ->middleware([AuthenticateApiClient::class, 'throttle:payment-status']);

    Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle'])
        ->middleware('throttle:webhook');
});
