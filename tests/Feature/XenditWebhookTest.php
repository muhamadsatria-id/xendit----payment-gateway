<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use App\Contracts\PaymentGatewayInterface;
use App\Models\Transaction;
use App\Models\Payment;
use App\Services\Payment\Webhooks\XenditWebhookService;


class XenditWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ip:127.0.0.1');

        config([
            'services.xendit.secret_key' => 'xnd_test_secret',
            'services.xendit.base_url' => 'https://api.xendit.co',
            'services.xendit.webhook_token' => 'webhook_test_token',
        ]);
    }

    public function test_paid_webhook_updates_transaction(): void
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-WEBHOOK-001',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        $payment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-001',
            'gateway_invoice_id' => 'inv_webhook_001',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        Http::fake([
            'api.xendit.co/v2/invoices/inv_webhook_001' =>
                Http::response([
                    'id' => 'inv_webhook_001',
                    'external_id' => 'INV-WEBHOOK-001',
                    'status' => 'PAID',
                    'amount' => 150000,
                    'paid_amount' => 150000,
                    'currency' => 'IDR',
                    'paid_at' => now()->toISOString(),
                ], 200),
        ]);

        $response = $this->postJson(
            '/api/v1/webhooks/xendit',
            [
                'id' => 'inv_webhook_001',
                'external_id' => 'INV-WEBHOOK-001',
                'status' => 'PAID',
            ],
            [
                'x-callback-token' => 'webhook_test_token',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('processed', true);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'PAID',
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => 'PAID',
        ]);

        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'event' => 'invoice.paid',
            'direction' => 'inbound',
        ]);
    }

    public function test_webhook_rejects_invalid_token(): void
    {
        $response = $this->postJson(
            '/api/v1/webhooks/xendit',
            [
                'id' => 'inv_webhook_001',
                'status' => 'PAID',
            ],
            [
                'x-callback-token' => 'wrong-token',
            ]
        );

        $response->assertUnauthorized();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_duplicate_paid_webhook_is_idempotent(): void
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-WEBHOOK-002',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        $payment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-002',
            'gateway_invoice_id' => 'inv_webhook_002',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        Http::fake([
            'api.xendit.co/v2/invoices/inv_webhook_002' =>
                Http::response([
                    'id' => 'inv_webhook_002',
                    'external_id' => 'INV-WEBHOOK-002',
                    'status' => 'PAID',
                    'amount' => 150000,
                    'paid_amount' => 150000,
                    'currency' => 'IDR',
                    'paid_at' => now()->toISOString(),
                ], 200),
        ]);

        $headers = [
            'x-callback-token' => 'webhook_test_token',
        ];

        $payload = [
            'id' => 'inv_webhook_002',
            'status' => 'PAID',
        ];

        $this->postJson(
            '/api/v1/webhooks/xendit',
            $payload,
            $headers
        )->assertOk();

        $this->postJson(
            '/api/v1/webhooks/xendit',
            $payload,
            $headers
        )
            ->assertOk()
            ->assertJsonPath('processed', false);

        $this->assertDatabaseCount('payment_logs', 1);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'PAID',
        ]);
    }

    public function test_expired_payment_does_not_downgrade_paid_transaction(): void
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-WEBHOOK-003',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PAID',
        ]);

        $payment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-003',
            'gateway_invoice_id' => 'inv_webhook_003',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        Http::fake([
            'api.xendit.co/v2/invoices/inv_webhook_003' =>
                Http::response([
                    'id' => 'inv_webhook_003',
                    'external_id' => 'INV-WEBHOOK-003',
                    'status' => 'EXPIRED',
                    'amount' => 150000,
                    'currency' => 'IDR',
                ], 200),
        ]);

        $response = $this->postJson(
            '/api/v1/webhooks/xendit',
            ['id' => 'inv_webhook_003'],
            ['x-callback-token' => 'webhook_test_token']
        );

        $response->assertOk()
            ->assertJsonPath('processed', true);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'EXPIRED',
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => 'PAID',
        ]);
    }

    public function test_expired_payment_keeps_transaction_pending_with_another_pending_attempt(): void
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-WEBHOOK-004',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        $expiredPayment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-004',
            'gateway_invoice_id' => 'inv_webhook_004',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        $pendingPayment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-005',
            'gateway_invoice_id' => 'inv_webhook_005',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        Http::fake([
            'api.xendit.co/v2/invoices/inv_webhook_004' =>
                Http::response([
                    'id' => 'inv_webhook_004',
                    'external_id' => 'INV-WEBHOOK-004',
                    'status' => 'EXPIRED',
                    'amount' => 150000,
                    'currency' => 'IDR',
                ], 200),
        ]);

        $response = $this->postJson(
            '/api/v1/webhooks/xendit',
            ['id' => 'inv_webhook_004'],
            ['x-callback-token' => 'webhook_test_token']
        );

        $response->assertOk()
            ->assertJsonPath('processed', true);

        $this->assertDatabaseHas('payments', [
            'id' => $expiredPayment->id,
            'status' => 'EXPIRED',
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $pendingPayment->id,
            'status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => 'PENDING',
        ]);
    }

    public function test_expired_payment_expires_transaction_when_no_other_payment_is_pending(): void
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-WEBHOOK-006',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        $payment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-006',
            'gateway_invoice_id' => 'inv_webhook_006',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        Http::fake([
            'api.xendit.co/v2/invoices/inv_webhook_006' =>
                Http::response([
                    'id' => 'inv_webhook_006',
                    'external_id' => 'INV-WEBHOOK-006',
                    'status' => 'EXPIRED',
                    'amount' => 150000,
                    'currency' => 'IDR',
                ], 200),
        ]);

        $response = $this->postJson(
            '/api/v1/webhooks/xendit',
            ['id' => 'inv_webhook_006'],
            ['x-callback-token' => 'webhook_test_token']
        );

        $response->assertOk()
            ->assertJsonPath('processed', true);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'EXPIRED',
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => 'EXPIRED',
        ]);
    }

    public function test_paid_webhook_rejects_paid_amount_that_does_not_match_invoice_amount(): void
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-WEBHOOK-007',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        $payment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-007',
            'gateway_invoice_id' => 'inv_webhook_007',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        Http::fake([
            'api.xendit.co/v2/invoices/inv_webhook_007' =>
                Http::response([
                    'id' => 'inv_webhook_007',
                    'external_id' => 'INV-WEBHOOK-007',
                    'status' => 'PAID',
                    'amount' => 150000,
                    'paid_amount' => 149999,
                    'currency' => 'IDR',
                    'paid_at' => now()->toISOString(),
                ], 200),
        ]);

        $response = $this->postJson(
            '/api/v1/webhooks/xendit',
            ['id' => 'inv_webhook_007'],
            ['x-callback-token' => 'webhook_test_token']
        );

        $response->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => 'PENDING',
        ]);

        $this->assertDatabaseCount('payment_logs', 0);
    }
    public function test_webhook_reconciles_invoice_when_gateway_invoice_id_is_null(): void
{
    $transaction = Transaction::create([
        'transaction_number' => 'TRX-RECON-001',
        'customer_name' => 'Test Customer',
        'customer_email' => 'customer@example.com',
        'amount' => 150000,
        'currency' => 'IDR',
        'status' => 'PENDING',
    ]);

    $payment = Payment::create([
        'transaction_id' => $transaction->id,
        'provider' => 'xendit',
        'external_id' => 'INV-RECON-001',
        'gateway_invoice_id' => null,
        'invoice_url' => null,
        'amount' => 150000,
        'currency' => 'IDR',
        'status' => 'PENDING',
    ]);

    $gateway = \Mockery::mock(
        \App\Contracts\PaymentGatewayInterface::class
    );

    $gateway->shouldReceive('getInvoice')
        ->once()
        ->with('xendit-invoice-recon-001')
        ->andReturn([
            'id' => 'xendit-invoice-recon-001',
            'external_id' => 'INV-RECON-001',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PAID',
            'paid_amount' => 150000,
            'invoice_url' => 'https://example.com/reconciled-invoice',
            'paid_at' => now()->toISOString(),
        ]);

    $this->app->instance(
        \App\Contracts\PaymentGatewayInterface::class,
        $gateway
    );

    $response = $this->postJson(
        '/api/v1/webhooks/xendit',
        ['id' => 'xendit-invoice-recon-001'],
        ['x-callback-token' => 'webhook_test_token']
    );

    $response->assertOk();

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'gateway_invoice_id' => 'xendit-invoice-recon-001',
        'status' => 'PAID',
    ]);

    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'status' => 'PAID',
    ]);

    $this->assertDatabaseHas('payment_logs', [
        'payment_id' => $payment->id,
        'event' => 'invoice.reconciled',
    ]);
}

public function test_webhook_rejects_missing_callback_token(): void
{
    $response = $this->postJson(
        '/api/v1/webhooks/xendit',
        ['id' => 'inv_missing_token']
    );

    $response->assertUnauthorized()
        ->assertJsonPath('success', false);

    $this->assertDatabaseCount('transactions', 0);
}

public function test_webhook_rejects_missing_invoice_id(): void
{
    $response = $this->postJson(
        '/api/v1/webhooks/xendit',
        [],
        ['x-callback-token' => 'webhook_test_token']
    );

    $response->assertStatus(400)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Invoice ID is required.');

    $this->assertDatabaseCount('transactions', 0);
}

public function test_webhook_returns_500_when_xendit_api_fails(): void
{
    $transaction = Transaction::create([
        'transaction_number' => 'TRX-WEBHOOK-ERROR-001',
        'customer_name' => 'Test Customer',
        'customer_email' => 'customer@example.com',
        'amount' => 150000,
        'currency' => 'IDR',
        'status' => 'PENDING',
    ]);

    $payment = $transaction->payments()->create([
        'provider' => 'xendit',
        'external_id' => 'INV-WEBHOOK-ERROR-001',
        'gateway_invoice_id' => 'inv_webhook_error_001',
        'amount' => 150000,
        'currency' => 'IDR',
        'status' => 'PENDING',
    ]);

    Http::fake([
        'api.xendit.co/v2/invoices/inv_webhook_error_001' =>
            Http::response([
                'message' => 'Internal server error',
            ], 500),
    ]);

    $response = $this->postJson(
        '/api/v1/webhooks/xendit',
        ['id' => 'inv_webhook_error_001'],
        ['x-callback-token' => 'webhook_test_token']
    );

    $response->assertStatus(500)
        ->assertJsonPath('success', false)
        ->assertJsonPath(
            'message',
            'Webhook processing failed.'
        );

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'PENDING',
    ]);

    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'status' => 'PENDING',
    ]);

    $this->assertDatabaseCount('payment_logs', 0);
}

public function test_webhook_returns_500_when_xendit_connection_times_out(): void
{
    $transaction = Transaction::create([
        'transaction_number' => 'TRX-WEBHOOK-TIMEOUT-001',
        'customer_name' => 'Test Customer',
        'customer_email' => 'customer@example.com',
        'amount' => 150000,
        'currency' => 'IDR',
        'status' => 'PENDING',
    ]);

    $payment = $transaction->payments()->create([
        'provider' => 'xendit',
        'external_id' => 'INV-WEBHOOK-TIMEOUT-001',
        'gateway_invoice_id' => 'inv_webhook_timeout_001',
        'amount' => 150000,
        'currency' => 'IDR',
        'status' => 'PENDING',
    ]);

    Http::fake([
        'api.xendit.co/v2/invoices/inv_webhook_timeout_001' =>
            function ($request, $options) {
                throw new \Illuminate\Http\Client\ConnectionException(
                    'Connection timed out.'
                );
            },
    ]);

    $response = $this->postJson(
        '/api/v1/webhooks/xendit',
        ['id' => 'inv_webhook_timeout_001'],
        ['x-callback-token' => 'webhook_test_token']
    );

    $response->assertStatus(500)
        ->assertJsonPath('success', false)
        ->assertJsonPath(
            'message',
            'Webhook processing failed.'
        );

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'PENDING',
    ]);

    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'status' => 'PENDING',
    ]);

    $this->assertDatabaseCount('payment_logs', 0);
}

    public function test_duplicate_webhook_with_same_event_id_creates_single_log(): void
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-WEBHOOK-DUP-EVENT',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        $payment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-DUP-EVENT',
            'gateway_invoice_id' => 'inv_webhook_dup_event',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        Http::fake([
            'api.xendit.co/v2/invoices/inv_webhook_dup_event' =>
                Http::response([
                    'id' => 'inv_webhook_dup_event',
                    'external_id' => 'INV-WEBHOOK-DUP-EVENT',
                    'status' => 'PAID',
                    'amount' => 150000,
                    'paid_amount' => 150000,
                    'currency' => 'IDR',
                    'paid_at' => now()->toISOString(),
                ], 200),
        ]);

        $headers = ['x-callback-token' => 'webhook_test_token'];
        $payload = [
            'id' => 'inv_webhook_dup_event',
            'webhook_id' => 'webhook-event-dup',
            'status' => 'PAID',
        ];

        $this->postJson(
            '/api/v1/webhooks/xendit',
            $payload,
            $headers
        )->assertOk()->assertJsonPath('processed', true);

        $this->postJson(
            '/api/v1/webhooks/xendit',
            $payload,
            $headers
        )->assertOk()->assertJsonPath('processed', false);

        $this->assertDatabaseCount('payment_logs', 1);
        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'event' => 'invoice.paid',
            'provider_event_id' => 'xendit-webhook-event-dup',
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'PAID',
        ]);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => 'PAID',
        ]);
    }

    public function test_concurrent_webhooks_do_not_double_apply_status(): void
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-WEBHOOK-CONCURRENT',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        $payment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-CONCURRENT',
            'gateway_invoice_id' => 'inv_webhook_concurrent',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        // Webhook kedua selesai saat webhook pertama masih menunggu verifikasi
        // invoice, sehingga state yang dibaca webhook pertama sudah stale
        // sebelum memasuki DB transaction.
        $gateway = new class implements PaymentGatewayInterface
        {
            public int $calls = 0;

            public ?XenditWebhookService $service = null;

            public function createInvoice(array $payload): array
            {
                return [];
            }

            public function findInvoiceByExternalId(string $externalId): ?array
            {
                return null;
            }

            public function expireInvoice(string $invoiceId): array
            {
                return [];
            }

            public function getInvoice(string $invoiceId): array
            {
                $this->calls++;

                if ($this->calls === 1) {
                    $this->service->handle([
                        'id' => $invoiceId,
                        'webhook_id' => 'webhook-concurrent-expired',
                    ]);

                    return [
                        'id' => $invoiceId,
                        'external_id' => 'INV-WEBHOOK-CONCURRENT',
                        'status' => 'PAID',
                        'amount' => 150000,
                        'paid_amount' => 150000,
                        'currency' => 'IDR',
                        'paid_at' => now()->toISOString(),
                    ];
                }

                return [
                    'id' => $invoiceId,
                    'external_id' => 'INV-WEBHOOK-CONCURRENT',
                    'status' => 'EXPIRED',
                    'amount' => 150000,
                    'currency' => 'IDR',
                ];
            }
        };

        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $gateway->service = $this->app->make(XenditWebhookService::class);

        $this->postJson(
            '/api/v1/webhooks/xendit',
            [
                'id' => 'inv_webhook_concurrent',
                'webhook_id' => 'webhook-concurrent-paid',
            ],
            ['x-callback-token' => 'webhook_test_token']
        )->assertOk()->assertJsonPath('processed', true);

        $this->assertSame(2, $gateway->calls);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'PAID',
        ]);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => 'PAID',
        ]);
        $this->assertDatabaseCount('payment_logs', 2);
        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'event' => 'invoice.expired',
            'provider_event_id' => 'xendit-webhook-concurrent-expired',
        ]);
        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'event' => 'invoice.paid',
            'provider_event_id' => 'xendit-webhook-concurrent-paid',
        ]);
    }

    public function test_late_expired_and_pending_webhooks_do_not_downgrade_paid_payment(): void
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-WEBHOOK-LATE-PAID',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PAID',
            'paid_at' => now(),
        ]);

        $payment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-LATE-PAID',
            'gateway_invoice_id' => 'inv_webhook_late_paid',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PAID',
            'paid_at' => now(),
        ]);

        Http::fake([
            'api.xendit.co/v2/invoices/inv_webhook_late_paid' =>
                Http::sequence()
                    ->push([
                        'id' => 'inv_webhook_late_paid',
                        'external_id' => 'INV-WEBHOOK-LATE-PAID',
                        'status' => 'EXPIRED',
                        'amount' => 150000,
                        'currency' => 'IDR',
                    ])
                    ->push([
                        'id' => 'inv_webhook_late_paid',
                        'external_id' => 'INV-WEBHOOK-LATE-PAID',
                        'status' => 'PENDING',
                        'amount' => 150000,
                        'currency' => 'IDR',
                    ]),
        ]);

        foreach (['EXPIRED', 'PENDING'] as $status) {
            $this->postJson(
                '/api/v1/webhooks/xendit',
                [
                    'id' => 'inv_webhook_late_paid',
                    'webhook_id' => 'webhook-late-' . strtolower($status),
                    'status' => $status,
                ],
                ['x-callback-token' => 'webhook_test_token']
            )->assertOk()->assertJsonPath('processed', false);
        }

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'PAID',
        ]);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => 'PAID',
        ]);
        $this->assertDatabaseCount('payment_logs', 0);
    }

    public function test_webhook_rejects_invoice_amount_that_does_not_match_payment_amount(): void
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-WEBHOOK-AMOUNT',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        $payment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-AMOUNT',
            'gateway_invoice_id' => 'inv_webhook_amount',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        Http::fake([
            'api.xendit.co/v2/invoices/inv_webhook_amount' =>
                Http::response([
                    'id' => 'inv_webhook_amount',
                    'external_id' => 'INV-WEBHOOK-AMOUNT',
                    'status' => 'PAID',
                    'amount' => 125000,
                    'paid_amount' => 125000,
                    'currency' => 'IDR',
                    'paid_at' => now()->toISOString(),
                ], 200),
        ]);

        $this->postJson(
            '/api/v1/webhooks/xendit',
            ['id' => 'inv_webhook_amount'],
            ['x-callback-token' => 'webhook_test_token']
        )->assertStatus(500)->assertJsonPath('success', false);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'PENDING',
        ]);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => 'PENDING',
        ]);
        $this->assertDatabaseCount('payment_logs', 0);
    }

    public function test_expired_webhook_does_not_expire_transaction_that_has_paid_payment(): void
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-WEBHOOK-MIXED',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-MIXED-PAID',
            'gateway_invoice_id' => 'inv_webhook_mixed_paid',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PAID',
        ]);

        $expiringPayment = $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-WEBHOOK-MIXED-EXPIRED',
            'gateway_invoice_id' => 'inv_webhook_mixed_expired',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PENDING',
        ]);

        Http::fake([
            'api.xendit.co/v2/invoices/inv_webhook_mixed_expired' =>
                Http::response([
                    'id' => 'inv_webhook_mixed_expired',
                    'external_id' => 'INV-WEBHOOK-MIXED-EXPIRED',
                    'status' => 'EXPIRED',
                    'amount' => 150000,
                    'currency' => 'IDR',
                ], 200),
        ]);

        $this->postJson(
            '/api/v1/webhooks/xendit',
            ['id' => 'inv_webhook_mixed_expired'],
            ['x-callback-token' => 'webhook_test_token']
        )->assertOk()->assertJsonPath('processed', true);

        $this->assertDatabaseHas('payments', [
            'id' => $expiringPayment->id,
            'status' => 'EXPIRED',
        ]);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => 'PENDING',
        ]);
    }

}
