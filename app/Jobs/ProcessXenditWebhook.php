<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\WebhookEvent;
use App\Services\Payment\PaymentCallbackService;
use App\Services\Payment\Webhooks\XenditWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessXenditWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function __construct(
        public int $webhookEventId
    ) {}

    public function handle(
        XenditWebhookService $webhookService,
        PaymentCallbackService $callbackService
    ): void {
        $event = WebhookEvent::findOrFail($this->webhookEventId);
        $event->increment('attempts');
        $event->update(['status' => 'processing']);

        try {
            $result = $webhookService->handle($event->payload ?? []);

            $event->update([
                'status' => 'processed',
                'result' => $result,
                'error' => null,
            ]);

            $transaction = $this->resolveTransaction($event);

            if ($transaction) {
                $callbackService->dispatchForTransaction(
                    $transaction->fresh() ?? $transaction
                );
            }
        } catch (Throwable $e) {
            $event->update([
                'status' => $this->attempts() >= $this->tries
                    ? 'failed'
                    : 'pending',
                'error' => get_class($e),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        $event?->update([
            'status' => 'failed',
            'error' => get_class($e),
        ]);

        $payment = $this->resolvePayment($event);

        $payment?->logs()->create([
            'event' => 'webhook.failed',
            'direction' => 'inbound',
            'payload' => [
                'webhook_event_id' => $this->webhookEventId,
                'provider_event_id' => $event?->provider_event_id,
                'exception_class' => get_class($e),
            ],
        ]);
    }

    private function resolveTransaction(WebhookEvent $event): ?Transaction
    {
        $payment = $this->resolvePayment($event);

        return $payment?->transaction;
    }

    private function resolvePayment(?WebhookEvent $event): ?Payment
    {
        if (! $event) {
            return null;
        }

        $payload = $event->payload ?? [];
        $query = Payment::where('provider', 'xendit');

        if (! empty($payload['external_id'])) {
            $payment = (clone $query)
                ->where('external_id', $payload['external_id'])
                ->first();

            if ($payment) {
                return $payment;
            }
        }

        if (! empty($payload['id'])) {
            return (clone $query)
                ->where('gateway_invoice_id', $payload['id'])
                ->first();
        }

        return null;
    }
}
