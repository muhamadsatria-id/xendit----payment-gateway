<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\Payment\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PDOException;
use Tests\TestCase;

class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->authenticateApiClient();

        RateLimiter::clear('client:' . $this->client->id);
    }

    private function paymentPayload(int $amount = 150000): array
    {
        return [
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'amount' => $amount,
            'currency' => 'IDR',
        ];
    }

    private function mockSuccessfulGateway(): void
    {
        $gateway = \Mockery::mock(PaymentGatewayInterface::class);

        $gateway->shouldReceive('createInvoice')
            ->once()
            ->andReturn([
                'id' => 'xendit-invoice-001',
                'invoice_url' => 'https://example.com/invoice/001',
                'status' => 'PENDING',
                'expiry_date' => now()->addDay()->toISOString(),
            ]);

        $this->app->instance(
            PaymentGatewayInterface::class,
            $gateway
        );
    }

    public function test_same_key_and_same_payload_reuses_existing_payment(): void
    {
        $this->mockSuccessfulGateway();

        $payload = $this->paymentPayload();

        $firstResponse = $this->postJson(
            '/api/v1/payments',
            $payload,
            $this->apiHeaders(['Idempotency-Key' => 'order-test-001'])
        );

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', false);

        $firstToken = $firstResponse->json('data.status_token');

        $this->assertNotEmpty($firstToken);

        $secondResponse = $this->postJson(
            '/api/v1/payments',
            $payload,
            $this->apiHeaders(['Idempotency-Key' => 'order-test-001'])
        );

        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath(
                'data.transaction_number',
                $firstResponse->json('data.transaction_number')
            );

        $secondToken = $secondResponse->json('data.status_token');

        $this->assertSame($firstToken, $secondToken);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('payments', 1);

        $transaction = Transaction::firstOrFail();

        $this->assertNotSame(
            $firstToken,
            $transaction->status_token_hash
        );
        $this->assertSame(
            hash('sha256', $firstToken),
            $transaction->status_token_hash
        );
        $this->assertNotEmpty(
            $transaction->status_token_encrypted
        );
    }

    public function test_same_key_with_different_payload_returns_conflict(): void
    {
        $this->mockSuccessfulGateway();

        $this->postJson(
            '/api/v1/payments',
            $this->paymentPayload(150000),
            $this->apiHeaders(['Idempotency-Key' => 'order-test-002'])
        )->assertCreated();

        $response = $this->postJson(
            '/api/v1/payments',
            $this->paymentPayload(250000),
            $this->apiHeaders(['Idempotency-Key' => 'order-test-002'])
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_pending_payment_without_invoice_does_not_call_gateway_again(): void
    {
        $payload = $this->paymentPayload();

        $requestHash = hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );

        $transaction = Transaction::create([
            'client_id' => $this->client->id,
            'transaction_number' => 'TRX-IDEMPOTENCY-001',
            'customer_name' => $payload['customer_name'],
            'customer_email' => $payload['customer_email'],
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'status' => 'PENDING',
            'idempotency_key' => 'order-test-003',
            'request_hash' => $requestHash,
        ]);

        Payment::create([
            'transaction_id' => $transaction->id,
            'provider' => 'xendit',
            'external_id' => 'INV-IDEMPOTENCY-001',
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'status' => 'PENDING',
        ]);

        $gateway = \Mockery::mock(PaymentGatewayInterface::class);

        $gateway->shouldNotReceive('createInvoice');

        $this->app->instance(
            PaymentGatewayInterface::class,
            $gateway
        );

        $response = $this->postJson(
            '/api/v1/payments',
            $payload,
            $this->apiHeaders(['Idempotency-Key' => 'order-test-003'])
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_parallel_requests_with_same_key_create_single_payment(): void
    {
        $payload = $this->paymentPayload();
        $key = 'order-test-parallel';

        $gateway = \Mockery::mock(PaymentGatewayInterface::class);

        $gateway->shouldReceive('createInvoice')
            ->once()
            ->andReturnUsing(function () use ($payload, $key) {
                $nested = $this->postJson(
                    '/api/v1/payments',
                    $payload,
                    $this->apiHeaders(['Idempotency-Key' => $key])
                );

                $nested->assertStatus(409);

                return [
                    'id' => 'xendit-invoice-parallel',
                    'invoice_url' =>
                        'https://example.com/invoice/parallel',
                    'status' => 'PENDING',
                    'expiry_date' => now()->addDay()->toISOString(),
                ];
            });

        $this->app->instance(
            PaymentGatewayInterface::class,
            $gateway
        );

        $outer = $this->postJson(
            '/api/v1/payments',
            $payload,
            $this->apiHeaders(['Idempotency-Key' => $key])
        );

        $outer->assertCreated();

        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_non_unique_database_error_is_not_treated_as_replay(): void
    {
        $payload = $this->paymentPayload();
        $key = 'order-test-db-error';

        Transaction::create([
            'client_id' => $this->client->id,
            'transaction_number' => 'TRX-IDEMPOTENCY-DB-ERROR',
            'customer_name' => $payload['customer_name'],
            'customer_email' => $payload['customer_email'],
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'status' => 'PENDING',
            'idempotency_key' => $key,
            'request_hash' => hash(
                'sha256',
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            ),
        ]);

        Payment::create([
            'transaction_id' => Transaction::firstOrFail()->id,
            'provider' => 'xendit',
            'external_id' => 'INV-IDEMPOTENCY-DB-ERROR',
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'status' => 'PENDING',
            'invoice_url' => 'https://example.com/invoice/existing',
        ]);

        $pdoError = new PDOException('Database unavailable', 0);
        $pdoError->errorInfo = ['HY000', 999, 'Database unavailable'];

        DB::shouldReceive('transaction')->andThrow(
            new QueryException(
                'sqlite',
                'select 1',
                [],
                $pdoError
            )
        );

        $this->expectException(QueryException::class);

        app(PaymentService::class)->createPayment(
            array_merge($payload, ['idempotency_key' => $key]),
            $this->client->id
        );
    }
}
