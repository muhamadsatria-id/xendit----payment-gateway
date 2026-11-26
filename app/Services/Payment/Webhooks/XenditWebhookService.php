<?php

namespace App\Services\Payment\Webhooks;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class XenditWebhookService
{
    public function __construct(
        protected PaymentGatewayInterface $gateway
    ) {}

    public function handle(array $payload): array
    {
        $invoiceId = $payload['id'] ?? null;

        if (! $invoiceId) {
            throw new RuntimeException('Invoice ID is missing.');
        }

        // Verify invoice directly with Xendit.
        $invoice = $this->gateway->getInvoice($invoiceId);

        if (($invoice['id'] ?? null) !== $invoiceId) {
            throw new RuntimeException('Invoice ID mismatch.');
        }

        return $this->syncInvoice($invoice, $payload['webhook_id'] ?? null);
    }

    public function syncInvoice(
        array $invoice,
        ?string $providerEventId = null
    ): array {
        $invoiceId = $invoice['id'] ?? null;

        if (! $invoiceId) {
            throw new RuntimeException('Invoice ID is missing.');
        }

        $externalId = $invoice['external_id'] ?? null;

        if (! $externalId) {
            throw new RuntimeException('External ID is missing.');
        }

        $payment = Payment::where('provider', 'xendit')
            ->where('external_id', $externalId)
            ->first();

        if (! $payment) {
            throw new RuntimeException('Payment not found.');
        }

        // Verify amount and currency before linking the invoice.
        if (
            (int) ($invoice['amount'] ?? 0) !== (int) $payment->amount ||
            strtoupper($invoice['currency'] ?? '') !== strtoupper($payment->currency)
        ) {
            throw new RuntimeException('Invoice data mismatch.');
        }

        $gatewayStatus = strtoupper($invoice['status'] ?? '');

        if (
            $gatewayStatus === 'PAID' &&
            isset($invoice['paid_amount']) &&
            (int) $invoice['paid_amount'] !== (int) $payment->amount
        ) {
            throw new RuntimeException(
                'Paid amount does not match payment amount.'
            );
        }

        if (! in_array($gatewayStatus, [
            'PAID',
            'EXPIRED',
            'PENDING',
        ], true)) {
            return [
                'processed' => false,
                'message' => 'Unsupported invoice status.',
            ];
        }

        return DB::transaction(function () use (
            $payment,
            $invoice,
            $invoiceId,
            $gatewayStatus,
            $providerEventId
        ) {
            $payment = Payment::whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Reject a different invoice already linked to this payment.
            if (
                $payment->gateway_invoice_id !== null &&
                $payment->gateway_invoice_id !== $invoiceId
            ) {
                throw new RuntimeException('Invoice data mismatch.');
            }

            // Recover an invoice whose creation response was lost.
            if ($payment->gateway_invoice_id === null) {
                $payment->update([
                    'gateway_invoice_id' => $invoiceId,
                    'invoice_url' => $invoice['invoice_url']
                        ?? $payment->invoice_url,
                    'expired_at' => $invoice['expiry_date']
                        ?? $payment->expired_at,
                    'gateway_response' => [
                        'id' => $invoiceId,
                        'status' => $gatewayStatus,
                        'expiry_date' => $invoice['expiry_date'] ?? null,
                    ],
                ]);

                $payment->logs()->create([
                    'event' => 'invoice.reconciled',
                    'direction' => 'inbound',
                    'payload' => [
                        'invoice_id' => $invoiceId,
                        'external_id' => $payment->external_id,
                        'status' => $gatewayStatus,
                    ],
                    'response_code' => 200,
                ]);

                $payment->refresh();
            }

            // A paid payment must never be downgraded.
            if ($payment->status === 'PAID') {
                return [
                    'processed' => false,
                    'message' => 'Payment already paid.',
                ];
            }

            // Do not allow stale PENDING notifications to downgrade EXPIRED.
            if (
                $payment->status === 'EXPIRED' &&
                $gatewayStatus === 'PENDING'
            ) {
                return [
                    'processed' => false,
                    'message' => 'Stale payment notification.',
                ];
            }

            if ($payment->status === $gatewayStatus) {
                return [
                    'processed' => false,
                    'message' => 'Payment status unchanged.',
                ];
            }

            $payment->update([
                'status' => $gatewayStatus,
                'paid_at' => $gatewayStatus === 'PAID'
                    ? ($invoice['paid_at'] ?? now())
                    : $payment->paid_at,
                'expired_at' => $gatewayStatus === 'EXPIRED'
                    ? ($invoice['expiry_date'] ?? now())
                    : $payment->expired_at,
            ]);

            $payment->logs()->create([
                'event' => 'invoice.' . strtolower($gatewayStatus),
                'direction' => 'inbound',
                'payload' => [
                    'invoice_id' => $invoiceId,
                    'external_id' => $payment->external_id,
                    'status' => $gatewayStatus,
                    'amount' => $invoice['amount'],
                    'currency' => $invoice['currency'],
                ],
                'response_code' => 200,
                'provider_event_id' => $providerEventId !== null
                    ? 'xendit-' . $providerEventId
                    : null,
            ]);

            $transaction = $payment->transaction()
                ->lockForUpdate()
                ->firstOrFail();

            if ($gatewayStatus === 'PAID') {
                if ($transaction->status !== 'PAID') {
                    $transaction->update([
                        'status' => 'PAID',
                        'paid_at' => $payment->paid_at,
                    ]);
                }
            } elseif (
                $gatewayStatus === 'EXPIRED' &&
                $transaction->status !== 'PAID' &&
                ! $transaction->payments()
                    ->where('status', 'PAID')
                    ->exists()
            ) {
                $hasPendingPayment = $transaction->payments()
                    ->where('id', '!=', $payment->id)
                    ->where('status', 'PENDING')
                    ->exists();

                if (! $hasPendingPayment) {
                    $transaction->update([
                        'status' => 'EXPIRED',
                    ]);
                }
            }

            return [
                'processed' => true,
                'message' => 'Payment status updated successfully.',
            ];
        });
    }
}
