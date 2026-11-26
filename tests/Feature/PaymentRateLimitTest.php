<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PaymentRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->authenticateApiClient();

        RateLimiter::clear('client:' . $this->client->id);
    }

    public function test_payment_creation_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/payments', [], $this->apiHeaders());
        }

        $response = $this->postJson(
            '/api/v1/payments',
            [],
            $this->apiHeaders()
        );

        $response->assertStatus(429);
    }

    public function test_payment_status_is_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson(
                '/api/v1/payments/TRX-NOT-FOUND',
                $this->apiHeaders()
            );
        }

        $response = $this->getJson(
            '/api/v1/payments/TRX-NOT-FOUND',
            $this->apiHeaders()
        );

        $response->assertStatus(429);
    }
}
