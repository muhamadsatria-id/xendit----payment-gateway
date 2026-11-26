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

#[Signature('payments:reconcile {--minutes=15 : Minimum age of pending transactions} {--limit=100 : Maximum transactions per run} {--dry-run : Report without changing state}')]
#[Description('Reconcile stale pending payments with Xendit invoice status.')]
class ReconcilePendingPayments extends Command
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
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, min(500, (int) $this->option('limit')));
        $cutoff = now()->subMinutes($minutes);

        $transactions = Transaction::query()
            ->where('status', 'PENDING')
            ->where('created_at', '<=', $cutoff)
            ->with([
                'payments' => fn ($query) => $query
                    ->where('status', 'PENDING')
                    ->latest('id'),
            ])
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($transactions as $transaction) {
            $payment = $transaction->payments->first();

            if (! $payment) {
                $skipped++;

                continue;
            }

            try {
                $invoice = $payment->gateway_invoice_id
                    ? $this->gateway->getInvoice($payment->gateway_invoice_id)
                    : $this->gateway->findInvoiceByExternalId(
                        $payment->external_id
                    );

                if (! is_array($invoice) || ! ($invoice['id'] ?? null)) {
                    $skipped++;

                    continue;
                }

                if (
                    $payment->gateway_invoice_id &&
                    $invoice['id'] !== $payment->gateway_invoice_id
                ) {
                    $failed++;
                    Log::warning('Reconciliation invoice ID mismatch.', [
                        'transaction_id' => $transaction->id,
                    ]);

                    continue;
                }

                if (
                    ($invoice['external_id'] ?? null) !==
                    $payment->external_id
                ) {
                    $failed++;
                    Log::warning('Reconciliation external ID mismatch.', [
                        'transaction_id' => $transaction->id,
                    ]);

                    continue;
                }

                if ($this->option('dry-run')) {
                    $processed++;

                    continue;
                }

                $result = $this->webhookService->syncInvoice(
                    $invoice,
                    'reconcile-' . $payment->id
                );

                $result['processed'] ? $processed++ : $skipped++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Payment reconciliation failed.', [
                    'transaction_id' => $transaction->id,
                    'exception_class' => get_class($e),
                ]);
            }
        }

        $this->info(sprintf(
            'Reconciliation finished: %d processed, %d skipped, %d failed.',
            $processed,
            $skipped,
            $failed
        ));

        return self::SUCCESS;
    }
}
