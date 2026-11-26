<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
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

    private function staleTransaction(
        string $number,
        string $externalId,
        ?string $invoiceId = 'inv_reconcile_001',
        string $status = 'PENDING'
    ): array {
        $old = now()->subMinutes(60);

        $transaction = Transaction::create([
            'transaction_number' => $number,
            'customer_name' => 'Reconcile Customer',
            'customer_email' => 'reconcile@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => $status,
        ]);

        // created_at tidak termasuk fillable, jadi ditetapkan langsung.
        $transaction->created_at = $old;
        $transaction->updated_at = $old;
        $transaction->save();

        $payment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => $externalId,
            'gateway_invoice_id' => $invoiceId,
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        return [$transaction, $payment];
    }

    public function test_reconciliation_recovers_invoice_when_gateway_invoice_id_is_missing(): void
    {
        [$transaction, $payment] = $this->staleTransaction(
            'TRX-RECONCILE-001',
            'INV-RECONCILE-001',
            null
        );

        Http::fake([
            'api.xendit.co/v2/invoices*' => Http::response([
                [
                    'id' => 'inv_recovered_001',
                    'external_id' => 'INV-RECONCILE-001',
                    'status' => 'PAID',
                    'amount' => 150000,
                    'paid_amount' => 150000,
                    'currency' => 'IDR',
                    'invoice_url' => 'https://checkout.xendit.co/recovered',
                    'expiry_date' => '2026-11-27T09:00:00.000Z',
                    'paid_at' => '2026-11-26T08:30:00.000Z',
                ],
            ], 200),
        ]);

        $this->artisan('payments:reconcile', ['--minutes' => 15])
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_starts_with(
                    $request->url(),
                    'https://api.xendit.co/v2/invoices?external_id='
                );
        });

        $payment->refresh();
        $transaction->refresh();

        $this->assertSame('inv_recovered_001', $payment->gateway_invoice_id);
        $this->assertSame(
            'https://checkout.xendit.co/recovered',
            $payment->invoice_url
        );
        $this->assertSame('PAID', $payment->status);
        $this->assertSame('PAID', $transaction->status);

        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'event' => 'invoice.reconciled',
        ]);
        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'event' => 'invoice.paid',
        ]);
    }

    public function test_reconciliation_marks_expired_invoice(): void
    {
        [$transaction, $payment] = $this->staleTransaction(
            'TRX-RECONCILE-002',
            'INV-RECONCILE-002',
            'inv_reconcile_002'
        );

        Http::fake([
            'api.xendit.co/v2/invoices/inv_reconcile_002' => Http::response([
                'id' => 'inv_reconcile_002',
                'external_id' => 'INV-RECONCILE-002',
                'status' => 'EXPIRED',
                'amount' => 150000,
                'currency' => 'IDR',
                'expiry_date' => '2026-11-26T08:00:00.000Z',
            ], 200),
        ]);

        $this->artisan('payments:reconcile', ['--minutes' => 15])
            ->assertExitCode(0);

        $this->assertSame('EXPIRED', $payment->fresh()->status);
        $this->assertSame('EXPIRED', $transaction->fresh()->status);

        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'event' => 'invoice.expired',
        ]);
    }

    public function test_reconciliation_ignores_recent_pending_transactions(): void
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-RECONCILE-003',
            'customer_name' => 'Recent Customer',
            'customer_email' => 'recent@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        $payment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-RECONCILE-003',
            'gateway_invoice_id' => 'inv_reconcile_003',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        Http::preventStrayRequests();

        $this->artisan('payments:reconcile', ['--minutes' => 15])
            ->assertExitCode(0);

        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertSame('PENDING', $transaction->fresh()->status);
    }

    public function test_reconciliation_continues_after_xendit_connection_failure(): void
    {
        [$transaction, $payment] = $this->staleTransaction(
            'TRX-RECONCILE-004',
            'INV-RECONCILE-004',
            'inv_reconcile_004'
        );

        Http::fake([
            'api.xendit.co/v2/invoices/inv_reconcile_004' =>
                Http::failedConnection(),
        ]);

        $this->artisan('payments:reconcile', ['--minutes' => 15])
            ->assertExitCode(0);

        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertSame('PENDING', $transaction->fresh()->status);
    }

    public function test_reconciliation_dry_run_does_not_change_state(): void
    {
        [$transaction, $payment] = $this->staleTransaction(
            'TRX-RECONCILE-005',
            'INV-RECONCILE-005',
            'inv_reconcile_005'
        );

        Http::fake([
            'api.xendit.co/v2/invoices/inv_reconcile_005' => Http::response([
                'id' => 'inv_reconcile_005',
                'external_id' => 'INV-RECONCILE-005',
                'status' => 'PAID',
                'amount' => 150000,
                'paid_amount' => 150000,
                'currency' => 'IDR',
            ], 200),
        ]);

        $this->artisan('payments:reconcile', [
            '--minutes' => 15,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertSame('PENDING', $transaction->fresh()->status);
        $this->assertDatabaseCount('payment_logs', 0);
    }
}
