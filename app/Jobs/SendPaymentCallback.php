<?php

namespace App\Jobs;

use App\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SendPaymentCallback implements ShouldQueue
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
        public int $transactionId
    ) {}

    public function handle(): void
    {
        $transaction = Transaction::with('client')
            ->findOrFail($this->transactionId);
        $client = $transaction->client;

        if (! $client || ! $client->callback_url) {
            return;
        }

        $secret = $client->decryptCallbackSecret();

        if (! $secret) {
            return;
        }

        $payment = $transaction->payments()
            ->latest('id')
            ->first();
        $timestamp = now()->toIso8601String();

        $payload = [
            'event' => 'payment.status',
            'transaction_number' => $transaction->transaction_number,
            'status' => $transaction->status,
            'amount' => (int) $transaction->amount,
            'currency' => $transaction->currency,
            'paid_at' => $transaction->paid_at?->toIso8601String(),
            'success_redirect_url' => $transaction->success_redirect_url,
            'failure_redirect_url' => $transaction->failure_redirect_url,
            'timestamp' => $timestamp,
            'payment' => $payment ? [
                'provider' => $payment->provider,
                'external_id' => $payment->external_id,
                'status' => $payment->status,
                'invoice_url' => $payment->invoice_url,
                'expired_at' => $payment->expired_at?->toIso8601String(),
            ] : null,
        ];

        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $signature = hash_hmac('sha256', $body, $secret);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Signature' => 'sha256=' . $signature,
                'X-Timestamp' => $timestamp,
            ])
                ->withBody($body)
                ->timeout(10)
                ->connectTimeout(5)
                ->post($client->callback_url);

            if ($response->successful()) {
                $this->logResult('callback.delivered', $response->status());

                return;
            }

            $this->logResult('callback.failed_attempt', $response->status());

            throw new RuntimeException(
                'Callback endpoint returned a non-success response.'
            );
        } catch (Throwable $e) {
            if (! isset($response)) {
                $this->logResult(
                    'callback.failed_attempt',
                    null,
                    get_class($e)
                );
            }

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $this->logResult('callback.failed', null, get_class($e));
    }

    private function logResult(
        string $event,
        ?int $responseCode,
        ?string $errorClass = null
    ): void {
        $transaction = Transaction::find($this->transactionId);
        $payment = $transaction?->payments()
            ->latest('id')
            ->first();

        if (! $transaction || ! $payment) {
            return;
        }

        $payload = [
            'transaction_number' => $transaction->transaction_number,
            'attempt' => max(1, $this->attempts()),
            'host' => parse_url(
                (string) ($transaction->client?->callback_url ?? ''),
                PHP_URL_HOST
            ),
        ];

        if ($errorClass !== null) {
            $payload['exception_class'] = $errorClass;
        }

        $payment->logs()->create([
            'event' => $event,
            'direction' => 'outbound',
            'payload' => $payload,
            'response_code' => $responseCode,
        ]);
    }
}
