<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Exceptions\ClientResourceNotFoundException;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Exceptions\PaymentRequestInProgressException;
use App\Models\Payment;
use Illuminate\Support\Facades\Crypt;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PaymentService
{
    public function __construct(
        protected PaymentGatewayInterface $gateway
    ) {}

    public function createPayment(array $data, int $clientId): array
    {
        $idempotencyKey = $data['idempotency_key'];

        $currency = strtoupper($data['currency'] ?? 'IDR');

        $requestData = [
            'customer_name' => $data['customer_name'],
            'customer_email' => $data['customer_email'],
            'amount' => (int) $data['amount'],
            'currency' => $currency,
        ];

        foreach (['success_redirect_url', 'failure_redirect_url'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $requestData[$field] = $data[$field];
            }
        }

        $requestHash = hash(
            'sha256',
            json_encode($requestData, JSON_UNESCAPED_UNICODE)
        );
        $statusToken = Str::random(64);

        $statusTokenHash = hash('sha256', $statusToken);
        $statusTokenEncrypted = Crypt::encryptString($statusToken);

        try {
            [$transaction, $payment] = DB::transaction(
                function () use (
                    $requestData,
                    $idempotencyKey,
                    $requestHash,
                    $statusTokenHash,
                    $statusTokenEncrypted,
                    $clientId
                ) {
                    $transaction = Transaction::create([
                        'client_id' => $clientId,
                        'transaction_number' => 'TRX-' . Str::upper(
                            (string) Str::ulid()
                        ),

                        'customer_name' => $requestData['customer_name'],
                        'customer_email' => $requestData['customer_email'],
                        'amount' => $requestData['amount'],
                        'currency' => $requestData['currency'],
                        'status' => 'PENDING',
                        'success_redirect_url' =>
                            $requestData['success_redirect_url'] ?? null,
                        'failure_redirect_url' =>
                            $requestData['failure_redirect_url'] ?? null,
                        'idempotency_key' => $idempotencyKey,
                        'request_hash' => $requestHash,
                        'status_token_hash' => $statusTokenHash,
                        'status_token_encrypted' => $statusTokenEncrypted,
                    ]);

                    $payment = $transaction->payments()->create([
                        'provider' => 'xendit',
                        'external_id' => 'INV-' . Str::uuid(),
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'status' => 'PENDING',
                    ]);

                    return [$transaction, $payment];
                }
            );
        } catch (QueryException $e) {
            // Hanya duplikasi idempotency key yang boleh dianggap replay.
            // Error DB lain tetap dilempar agar tidak disamarkan.
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            $existing = Transaction::with('payments')
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing) {
                throw $e;
            }

            return $this->resolveExisting(
                $existing,
                $requestHash,
                $clientId
            );
        }

        $payload = [
            'external_id' => $payment->external_id,
            'amount' => $payment->amount,
            'description' => 'Payment ' . $transaction->transaction_number,
            'invoice_duration' => 86400,
            'currency' => $payment->currency,
            'payer_email' => $transaction->customer_email,
        ];

        try {
            $response = $this->gateway->createInvoice($payload);

            if (
                empty($response['id']) ||
                empty($response['invoice_url'])
            ) {
                throw new PaymentGatewayException(
                    'Xendit returned an incomplete invoice response.'
                );
            }

            DB::transaction(function () use ($payment, $response) {
                $payment->update([
                    'gateway_invoice_id' => $response['id'],
                    'invoice_url' => $response['invoice_url'],
                    'status' => 'PENDING',
                    'expired_at' => $response['expiry_date'] ?? null,
                    'gateway_response' => [
                        'id' => $response['id'],
                        'status' => $response['status'] ?? null,
                        'expiry_date' => $response['expiry_date'] ?? null,
                    ],
                ]);

                $payment->logs()->create([
                    'event' => 'invoice.created',
                    'direction' => 'outbound',
                    'payload' => [
                        'external_id' => $payment->external_id,
                        'gateway_invoice_id' => $response['id'],
                    ],
                    'response_code' => 200,
                ]);
            });

            return [
                'transaction' => $transaction->fresh(),
                'payment' => $payment->fresh(),
                'status_token' => $statusToken,
                'replayed' => false,
            ];

        } catch (Throwable $e) {
            try {
                $payment->logs()->create([
                    'event' => 'invoice.create_failed',
                    'direction' => 'outbound',
                    'payload' => [
                        'error' => $e instanceof PaymentGatewayException
                            ? $e->getMessage()
                            : 'Unexpected payment processing error.',
                    ],
                    'response_code' => $e instanceof PaymentGatewayException
                        ? $e->statusCode
                        : null,
                ]);

                if (
                    $e instanceof PaymentGatewayException &&
                    $e->statusCode !== null &&
                    $e->statusCode < 500
                ) {
                    $payment->update(['status' => 'FAILED']);
                }
            } catch (Throwable $logError) {
                // Jangan tutup error asli dengan kegagalan penulisan log.
                report($logError);
            }

            throw $e;
        }
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        if ((string) $e->getCode() !== '23000') {
            return false;
        }

        return in_array(
            (int) ($e->errorInfo[1] ?? 0),
            [19, 1062, 2067],
            true
        );
    }

    private function resolveExisting(
        Transaction $transaction,
        string $requestHash,
        int $clientId
    ): array {
        // Idempotency key bersifat global; jangan sampai client lain bisa
        // mereplay transaksi milik client lain hanya dengan menebak key-nya.
        if ((int) $transaction->client_id !== $clientId) {
            throw new ClientResourceNotFoundException(
                'Transaction not found.'
            );
        }

        if (! hash_equals(
            (string) $transaction->request_hash,
            $requestHash
        )) {
            throw new IdempotencyKeyConflictException(
                'This Idempotency-Key has already been used with different data.'
            );
        }

        $payment = $transaction->payments
            ->sortByDesc('id')
            ->first();

        if (! $payment) {
            throw new PaymentRequestInProgressException(
                'Payment is still being processed.'
            );
        }

        // Invoice sudah tersedia, atau pembayaran sudah berstatus terminal.
        if ($payment->invoice_url || $payment->status !== 'PENDING') {
            if (empty($transaction->status_token_encrypted)) {
                throw new PaymentRequestInProgressException(
                    'Status token is unavailable for this transaction.'
                );
            }

            return [
                'transaction' => $transaction,
                'payment' => $payment,
                'status_token' => Crypt::decryptString(
                    $transaction->status_token_encrypted
                ),
                'replayed' => true,
            ];
        }

        // Jangan membuat invoice baru jika hasil request sebelumnya belum pasti.
        throw new PaymentRequestInProgressException(
            'Payment is still being processed. Please check again shortly.'
        );
    }
}
