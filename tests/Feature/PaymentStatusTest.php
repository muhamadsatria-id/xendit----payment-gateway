<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private function createPaymentData(): Transaction
    {
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-TEST-001',
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PAID',
            'paid_at' => now(),
            'status_token_hash' => hash(
            'sha256',
            'test-status-token-123'
        ),
        ]);

        Payment::create([
            'transaction_id' => $transaction->id,
            'provider' => 'xendit',
            'external_id' => 'INV-TEST-001',
            'gateway_invoice_id' => 'xendit-invoice-001',
            'invoice_url' => 'https://example.com/invoice',
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => 'PAID',
            'paid_at' => now(),
            'gateway_response' => [
                'secret' => 'this-must-not-be-exposed',
            ],
        ]);

        return $transaction;
    }

    public function test_can_get_payment_status_by_transaction_number(): void
    {
        $transaction = $this->createPaymentData();

        $response = $this->getJson(
    "/api/v1/payments/{$transaction->transaction_number}",
    ['X-Status-Token' => 'test-status-token-123']
);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.transaction_number',
                'TRX-TEST-001'
            )
            ->assertJsonPath('data.amount', 150000)
            ->assertJsonPath('data.currency', 'IDR')
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.payment.provider', 'xendit')
            ->assertJsonPath('data.payment.status', 'PAID')
            ->assertJsonPath(
                'data.payment.payment_url',
                'https://example.com/invoice'
            )
            ->assertJsonMissingPath('data.payment.gateway_response')
            ->assertJsonMissingPath('data.customer_name')
            ->assertJsonMissingPath('data.customer_email')
            ->assertJsonMissingPath('data.payment.external_id')
            ->assertJsonMissingPath('data.payment.gateway_invoice_id');
    }

    public function test_returns_404_when_transaction_does_not_exist(): void
    {
        $response = $this->getJson(
            '/api/v1/payments/TRX-NOT-FOUND'
        );

        $response
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'Transaction not found.'
            );
    }
    public function test_rejects_status_request_without_token(): void
{
    $transaction = $this->createPaymentData();

    $this->getJson(
        "/api/v1/payments/{$transaction->transaction_number}"
    )->assertUnauthorized();
}

public function test_rejects_status_request_with_invalid_token(): void
{
    $transaction = $this->createPaymentData();

    $this->getJson(
        "/api/v1/payments/{$transaction->transaction_number}",
        ['X-Status-Token' => 'wrong-token']
    )->assertUnauthorized();
}
}