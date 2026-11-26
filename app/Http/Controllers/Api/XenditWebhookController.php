<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\Webhooks\XenditWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class XenditWebhookController extends Controller
{
    public function __construct(
        protected XenditWebhookService $webhookService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $callbackToken = config('services.xendit.webhook_token');
        $receivedToken = $request->header('x-callback-token');

        if (
            empty($callbackToken) ||
            empty($receivedToken) ||
            ! hash_equals($callbackToken, $receivedToken)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook token.',
            ], 401);
        }

        // Validate required invoice ID before calling the gateway.
        $invoiceId = $request->input('id');

        if (! is_string($invoiceId) || trim($invoiceId) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Invoice ID is required.',
            ], 400);
        }

        try {
            $result = $this->webhookService->handle(
                $request->all()
            );

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'processed' => $result['processed'],
            ]);

        } catch (\Throwable $e) {
            // Do not expose or log raw provider/database error messages.
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
