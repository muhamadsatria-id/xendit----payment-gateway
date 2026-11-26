
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\XenditWebhookController;

Route::prefix('v1')->group(function () {
    
    Route::post('/payments', [
    PaymentController::class,
    'store',
    ])->middleware('throttle:payment-create');

    Route::get('/payments/{transactionNumber}', [
        PaymentController::class,
        'show',
    ])->middleware('throttle:payment-status');

    Route::post(
        '/webhooks/xendit',
        [XenditWebhookController::class, 'handle']
    );
});