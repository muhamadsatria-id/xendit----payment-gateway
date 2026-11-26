<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CancelPaymentInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.xendit.secret_key' => 'xnd_test_secret',
            'services.xendit.base_url' => 'https://api.xendit.co',
        ]);
    }

    private function pendingTransaction(
        string $number = 'TRX-CANCEL-001',
        string $invoiceId = 'inv_cancel_001',
        string $externalId = 'INV-CANCEL-001',
        string $transactionStatus = 'PENDING',
        string $paymentStatus = 'PENDING'
    ): array {
        $transaction = Transaction::create([
            'transaction_number' => $number,
            'customer_name' => 'Cancel Customer',
            'customer_email' => 'cancel@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => $transactionStatus,
        ]);

        $payment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => $externalId,
            'gateway_invoice_id' => $invoiceId,
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => $paymentStatus,
        ]);

        return [$transaction, $payment];
    }

    public function test_cancel_expires_pending_invoice_and_marks_payment_expired(): void
    {
        [$transaction, $payment] = $this->pendingTransaction();

        Http::fake([
            'api.xendit.co/invoices/inv_cancel_001/expire!' => Http::response([
                'id' => 'inv_cancel_001',
                'external_id' => 'INV-CANCEL-001',
                'status' => 'EXPIRED',
            ], 200),
            'api.xendit.co/v2/invoices/inv_cancel_001' => Http::sequence()
                ->push([
                    'id' => 'inv_cancel_001',
                    'external_id' => 'INV-CANCEL-001',
                    'status' => 'PENDING',
                    'amount' => 150000,
                    'currency' => 'IDR',
                ], 200)
                ->push([
                    'id' => 'inv_cancel_001',
                    'external_id' => 'INV-CANCEL-001',
                    'status' => 'EXPIRED',
                    'amount' => 150000,
                    'currency' => 'IDR',
                    'expiry_date' => '2026-11-26T08:00:00.000Z',
                ], 200),
        ]);

        $this->artisan('payments:cancel', [
            'transaction' => $transaction->transaction_number,
        ])->assertExitCode(0);

        Http::assertSent(function (ClientRequest $request) {
            return $request->method() === 'POST'
                && $request->url() ===
                    'https://api.xendit.co/invoices/inv_cancel_001/expire!';
        });

        $this->assertSame('EXPIRED', $payment->fresh()->status);
        $this->assertSame('EXPIRED', $transaction->fresh()->status);

        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'event' => 'invoice.expired',
        ]);
    }

    public function test_cancel_refuses_already_paid_invoice(): void
    {
        [$transaction, $payment] = $this->pendingTransaction(
            'TRX-CANCEL-002',
            'inv_cancel_002',
            'INV-CANCEL-002',
            'PAID',
            'PAID'
        );

        Http::fake([
            'api.xendit.co/v2/invoices/inv_cancel_002' => Http::response([
                'id' => 'inv_cancel_002',
                'external_id' => 'INV-CANCEL-002',
                'status' => 'PAID',
                'amount' => 150000,
                'paid_amount' => 150000,
                'currency' => 'IDR',
            ], 200),
        ]);

        $this->artisan('payments:cancel', [
            'transaction' => $transaction->transaction_number,
        ])->assertExitCode(1);

        Http::assertNotSent(function (ClientRequest $request) {
            return str_contains($request->url(), 'expire!');
        });

        $this->assertSame('PAID', $payment->fresh()->status);
        $this->assertSame('PAID', $transaction->fresh()->status);
    }

    public function test_cancel_reports_xendit_timeout_without_changing_state(): void
    {
        [$transaction, $payment] = $this->pendingTransaction(
            'TRX-CANCEL-003',
            'inv_cancel_003',
            'INV-CANCEL-003'
        );

        Http::fake([
            'api.xendit.co/invoices/inv_cancel_003/expire!' =>
                Http::failedConnection(),
            'api.xendit.co/v2/invoices/inv_cancel_003' => Http::response([
                'id' => 'inv_cancel_003',
                'external_id' => 'INV-CANCEL-003',
                'status' => 'PENDING',
                'amount' => 150000,
                'currency' => 'IDR',
            ], 200),
        ]);

        $this->artisan('payments:cancel', [
            'transaction' => $transaction->transaction_number,
        ])->assertExitCode(1);

        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertSame('PENDING', $transaction->fresh()->status);
        $this->assertDatabaseCount('payment_logs', 0);
    }

    public function test_cancel_rejects_unknown_transaction(): void
    {
        Http::preventStrayRequests();

        $this->artisan('payments:cancel', [
            'transaction' => 'TRX-DOES-NOT-EXIST',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('transactions', 0);
    }
}
