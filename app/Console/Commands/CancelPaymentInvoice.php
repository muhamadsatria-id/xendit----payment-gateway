<?php

namespace App\Console\Commands;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Transaction;
use App\Services\Payment\Webhooks\XenditWebhookService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('payments:cancel {transaction : Transaction number}')]
#[Description('Expire a pending Xendit invoice and mark the payment as expired.')]
class CancelPaymentInvoice extends Command
{
    public function __construct(
        protected PaymentGatewayInterface $gateway,
        protected XenditWebhookService $webhookService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $transaction = Transaction::with([
            'payments' => fn ($query) => $query->latest('id'),
        ])
            ->where('transaction_number', $this->argument('transaction'))
            ->first();

        if (! $transaction) {
            $this->error('Transaction not found.');

            return self::FAILURE;
        }

        if ($transaction->status === 'PAID') {
            $this->error('Paid transaction cannot be cancelled.');

            return self::FAILURE;
        }

        $payment = $transaction->payments
            ->firstWhere('status', 'PENDING');

        if (! $payment) {
            $this->error('No pending payment to cancel.');

            return self::FAILURE;
        }

        try {
            $invoice = $payment->gateway_invoice_id
                ? $this->gateway->getInvoice($payment->gateway_invoice_id)
                : $this->gateway->findInvoiceByExternalId(
                    $payment->external_id
                );

            if (! is_array($invoice) || ! ($invoice['id'] ?? null)) {
                $this->error('Xendit invoice not found.');

                return self::FAILURE;
            }

            $status = strtoupper($invoice['status'] ?? '');

            if ($status === 'PAID') {
                $this->webhookService->syncInvoice(
                    $invoice,
                    'cancel-' . $payment->id
                );
                $this->error(
                    'Invoice is already paid; local state was synchronized.'
                );

                return self::FAILURE;
            }

            if ($status !== 'EXPIRED') {
                $this->gateway->expireInvoice($invoice['id']);
                $invoice = $this->gateway->getInvoice($invoice['id']);
            }

            $result = $this->webhookService->syncInvoice(
                $invoice,
                'cancel-' . $payment->id
            );

            $this->info(
                $result['processed']
                    ? 'Invoice cancelled.'
                    : $result['message']
            );

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::warning('Manual payment cancel failed.', [
                'transaction_id' => $transaction->id,
                'exception_class' => get_class($e),
            ]);

            $this->error('Unable to cancel invoice. Check logs.');

            return self::FAILURE;
        }
    }
}
