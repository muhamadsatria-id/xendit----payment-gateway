<?php

use App\Models\Client;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreatePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->authenticateApiClient();
    }

    public function test_payment_creation_requires_idempotency_key(): void
    {
        $response = $this->postJson(
            '/api/v1/payments',
            [
                'customer_name' => 'Test Customer',
                'customer_email' => 'customer@example.com',
                'amount' => 150000,
                'currency' => 'IDR',
            ],
            $this->apiHeaders()
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_customer_can_create_payment(): void
    {
        config([
            'services.xendit.secret_key' => 'xnd_test_secret',
            'services.xendit.base_url' => 'https://api.xendit.co',
        ]);

        Http::fake([
            'api.xendit.co/v2/invoices' => Http::response([
                'id' => 'inv_test_123',
                'external_id' => 'INV-test',
                'status' => 'PENDING',
                'invoice_url' => 'https://checkout.xendit.co/test',
                'expiry_date' => '2026-11-27T09:00:00.000Z',
            ], 200),
        ]);

        $response = $this->postJson(
            '/api/v1/payments',
            [
                'customer_name' => 'John Doe',
                'customer_email' => 'john@example.com',
                'amount' => 150000,
                'currency' => 'IDR',
            ],
            $this->apiHeaders([
                'Idempotency-Key' => 'create-payment-test-001',
            ])
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.payment_url',
                'https://checkout.xendit.co/test'
            );

        $this->assertDatabaseHas('transactions', [
            'client_id' => $this->client->id,
            'customer_email' => 'john@example.com',
            'amount' => 150000,
            'status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('payments', [
            'provider' => 'xendit',
            'gateway_invoice_id' => 'inv_test_123',
            'status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('payment_logs', [
            'event' => 'invoice.created',
            'direction' => 'outbound',
        ]);

        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_payment_request_validation_fails(): void
    {
        $response = $this->postJson(
            '/api/v1/payments',
            [
                'customer_name' => '',
                'customer_email' => 'invalid-email',
                'amount' => 100,
            ],
            $this->apiHeaders([
                'Idempotency-Key' => 'create-payment-test-002',
            ])
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'customer_name',
                'customer_email',
                'amount',
            ]);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('payments', 0);
    }
}
