<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use PDOException;
use Tests\TestCase;

class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected ?string $originalApiKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->authenticateApiClient('Client A');
        $this->originalApiKey = $this->apiKey;

        RateLimiter::clear('client:' . $this->client->id);
        RateLimiter::clear('ip:127.0.0.1');

        config([
            'services.xendit.webhook_token' => 'webhook_test_token',
        ]);
    }

    public function test_create_rejects_missing_api_key(): void
    {
        $this->postJson('/api/v1/payments', [])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid or missing API key.');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_create_rejects_invalid_api_key(): void
    {
        $this->postJson('/api/v1/payments', [], [
            'X-API-Key' => 'pgk_' . str_repeat('x', 64),
        ])->assertUnauthorized();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_api_key_is_stored_only_as_hash(): void
    {
        $this->assertFalse(Schema::hasColumn('clients', 'api_key'));

        $this->assertDatabaseHas('clients', [
            'id' => $this->client->id,
            'api_key_hash' => hash('sha256', $this->apiKey),
        ]);

        $this->assertDatabaseMissing('clients', [
            'api_key_hash' => $this->apiKey,
        ]);
    }

    public function test_client_cannot_read_another_clients_transaction(): void
    {
        $other = $this->authenticateApiClient('Client B');

        $transaction = Transaction::create([
            'client_id' => $other->id,
            'transaction_number' => 'TRX-OTHER-001',
            'customer_name' => 'Other Customer',
            'customer_email' => 'other@example.com',
            'amount' => 99000,
            'currency' => 'IDR',
            'status' => 'PAID',
            'status_token_hash' => hash('sha256', 'other-status-token'),
        ]);

        // Kembali ke API key Client A.
        $this->apiKey = $this->originalApiKey;

        $this->getJson(
            "/api/v1/payments/{$transaction->transaction_number}",
            $this->apiHeaders(['X-Status-Token' => 'other-status-token'])
        )->assertNotFound();
    }

    public function test_client_cannot_replay_another_clients_idempotency_key(): void
    {
        $other = $this->authenticateApiClient('Client B');

        $payload = [
            'customer_name' => 'Other Customer',
            'customer_email' => 'other@example.com',
            'amount' => 99000,
            'currency' => 'IDR',
        ];

        $transaction = Transaction::create([
            'client_id' => $other->id,
            'transaction_number' => 'TRX-OTHER-002',
            'customer_name' => $payload['customer_name'],
            'customer_email' => $payload['customer_email'],
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'status' => 'PENDING',
            'idempotency_key' => 'shared-key',
            'request_hash' => hash(
                'sha256',
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            ),
            'status_token_hash' => hash('sha256', 'other-status-token'),
            'status_token_encrypted' => encrypt('other-status-token'),
        ]);

        $transaction->payments()->create([
            'provider' => 'xendit',
            'external_id' => 'INV-OTHER-002',
            'gateway_invoice_id' => 'inv_other_002',
            'invoice_url' => 'https://example.com/other',
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'status' => 'PENDING',
        ]);

        $gateway = \Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldNotReceive('createInvoice');

        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        // Kembali ke API key Client A.
        $this->apiKey = $this->originalApiKey;

        $this->postJson(
            '/api/v1/payments',
            $payload,
            $this->apiHeaders(['Idempotency-Key' => 'shared-key'])
        )->assertNotFound();

        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_internal_error_response_does_not_leak_sql_or_path(): void
    {
        $pdoError = new PDOException('Database unavailable', 0);
        $pdoError->errorInfo = ['HY000', 999, 'Database unavailable'];

        DB::shouldReceive('transaction')->andThrow(
            new QueryException(
                'sqlite',
                "select * from secret_leak_table where token='leak_marker_123'",
                [],
                $pdoError
            )
        );

        $response = $this->postJson(
            '/api/v1/payments',
            [
                'customer_name' => 'Audit Customer',
                'customer_email' => 'audit@example.com',
                'amount' => 100000,
                'currency' => 'IDR',
            ],
            $this->apiHeaders(['Idempotency-Key' => 'security-error-001'])
        );

        $response
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'An internal error occurred.');

        $content = (string) $response->getContent();

        $this->assertStringNotContainsString('secret_leak_table', $content);
        $this->assertStringNotContainsString('leak_marker_123', $content);
        $this->assertStringNotContainsString('SELECT', $content);
    }

    public function test_cors_allows_only_configured_origins(): void
    {
        config([
            'cors.allowed_origins' => ['https://app.example.com'],
        ]);

        $allowed = $this->getJson(
            '/api/v1/payments/TRX-NOT-FOUND',
            $this->apiHeaders([
                'Origin' => 'https://app.example.com',
            ])
        );

        $allowed->assertHeader(
            'Access-Control-Allow-Origin',
            'https://app.example.com'
        );

        $blocked = $this->getJson(
            '/api/v1/payments/TRX-NOT-FOUND',
            $this->apiHeaders([
                'Origin' => 'https://evil.example.com',
            ])
        );

        // Laravel mengembalikan origin pertama yang diizinkan untuk origin
        // yang tidak cocok; browser tetap memblokir karena tidak sama dengan
        // Origin request.
        $blocked->assertHeader(
            'Access-Control-Allow-Origin',
            'https://app.example.com'
        );

        $this->assertStringNotContainsString(
            'evil.example.com',
            (string) $blocked->headers->get('Access-Control-Allow-Origin')
        );
    }

    public function test_cors_preflight_allows_api_key_header(): void
    {
        config([
            'cors.allowed_origins' => ['https://app.example.com'],
        ]);

        $response = $this->call('OPTIONS', '/api/v1/payments', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' =>
                'x-api-key, content-type, idempotency-key',
        ]);

        $response->assertHeader(
            'Access-Control-Allow-Origin',
            'https://app.example.com'
        );

        $this->assertStringContainsString(
            'x-api-key',
            strtolower(
                (string) $response->headers->get('Access-Control-Allow-Headers')
            )
        );
    }

    public function test_webhook_is_rate_limited(): void
    {
        RateLimiter::clear('ip:127.0.0.1');

        for ($i = 0; $i < 100; $i++) {
            $this->postJson('/api/v1/webhooks/xendit', [
                'id' => 'inv_rate_limit',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/webhooks/xendit', [
            'id' => 'inv_rate_limit',
        ])->assertStatus(429);
    }
}
