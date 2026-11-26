<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PaymentServiceAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->authenticateApiClient();

        RateLimiter::clear('client:' . $this->client->id);

        config([
            'services.xendit.secret_key' => 'xnd_test_secret',
            'services.xendit.base_url' => 'https://api.xendit.co',
            'services.xendit.webhook_token' => 'webhook_test_token',
        ]);
    }

    private function payload(): array
    {
        return [
            'customer_name' => 'Audit Customer',
            'customer_email' => 'audit.customer@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
        ];
    }

    private function successfulInvoiceResponse(): array
    {
        return [
            'id' => 'inv_audit_001',
            'external_id' => 'INV-AUDIT-001',
            'status' => 'PENDING',
            'invoice_url' => 'https://checkout.xendit.co/audit',
            'expiry_date' => '2026-11-27T09:00:00.000Z',
        ];
    }

    public function test_xendit_timeout_returns_generic_502_and_keeps_payment_pending(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::failedConnection(),
        ]);

        $response = $this->postJson(
            '/api/v1/payments',
            $this->payload(),
            $this->apiHeaders([
                'Idempotency-Key' => 'audit-timeout-001',
            ])
        );

        $response
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'Unable to create payment. Please check payment status before retrying.'
            );

        $payment = Payment::firstOrFail();

        $this->assertSame('PENDING', $payment->status);
        $this->assertNull($payment->gateway_invoice_id);
        $this->assertNull($payment->invoice_url);

        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'event' => 'invoice.create_failed',
            'direction' => 'outbound',
        ]);

        $log = PaymentLog::firstOrFail();

        $this->assertSame(
            'Unable to connect to Xendit.',
            $log->payload['error']
        );

        // POST pembuatan invoice sengaja tidak di-retry.
        Http::assertSentCount(1);
    }

    public function test_xendit_4xx_error_does_not_leak_provider_response(): void
    {
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'message' => 'xnd_secret_should_not_leak',
            ], 400),
        ]);

        $response = $this->postJson(
            '/api/v1/payments',
            $this->payload(),
            $this->apiHeaders([
                'Idempotency-Key' => 'audit-4xx-001',
            ])
        );

        $response
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'Unable to create payment. Please check payment status before retrying.'
            );

        $this->assertStringNotContainsString(
            'xnd_secret_should_not_leak',
            $response->getContent()
        );

        $payment = Payment::firstOrFail();

        $this->assertSame('FAILED', $payment->status);

        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'event' => 'invoice.create_failed',
            'response_code' => 400,
        ]);
    }

    public function test_payment_logs_do_not_contain_sensitive_request_data(): void
    {
        Http::fake([
            'api.xendit.co/v2/invoices' =>
                Http::response($this->successfulInvoiceResponse(), 200),
        ]);

        $this->postJson(
            '/api/v1/payments',
            $this->payload(),
            $this->apiHeaders([
                'Idempotency-Key' => 'audit-log-001',
            ])
        )->assertCreated();

        $logs = json_encode(
            PaymentLog::query()->pluck('payload')->all(),
            JSON_UNESCAPED_UNICODE
        );

        $this->assertIsString($logs);
        $this->assertStringNotContainsString(
            'audit.customer@example.com',
            $logs
        );
        $this->assertStringNotContainsString('Audit Customer', $logs);
        $this->assertStringNotContainsString('xnd_test_secret', $logs);
        $this->assertStringNotContainsString('webhook_test_token', $logs);
    }

    public function test_amount_and_expiry_are_stored_as_integer_and_utc(): void
    {
        Http::fake([
            'api.xendit.co/v2/invoices' =>
                Http::response($this->successfulInvoiceResponse(), 200),
        ]);

        $this->postJson(
            '/api/v1/payments',
            $this->payload(),
            $this->apiHeaders([
                'Idempotency-Key' => 'audit-amount-001',
            ])
        )->assertCreated();

        $payment = Payment::firstOrFail();

        $this->assertSame(150000, $payment->amount);
        $this->assertSame('UTC', $payment->expired_at->timezoneName);
        $this->assertSame(
            '2026-11-27 09:00:00',
            $payment->expired_at->toDateTimeString()
        );
        $this->assertSame('UTC', config('app.timezone'));
    }
}
