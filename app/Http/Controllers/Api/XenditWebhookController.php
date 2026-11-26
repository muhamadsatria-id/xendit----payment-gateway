<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebhookRequest;
use App\Services\Payment\Webhooks\XenditWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class XenditWebhookController extends Controller
{
    public function __construct(
        protected XenditWebhookService $webhookService
    ) {}

    public function handle(WebhookRequest $request): JsonResponse
    {
        try {
            $result = $this->webhookService->handle(
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'processed' => $result['processed'],
            ]);
        } catch (\Throwable $e) {
            // Jangan mengekspos atau mencatat pesan error provider/database mentah.
            Log::error('Xendit webhook processing failed.', [
                'exception_class' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed.',
            ], 500);
        }
    }
}
