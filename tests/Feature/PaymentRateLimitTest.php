<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PaymentRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_creation_is_rate_limited(): void
    {
        RateLimiter::clear('127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/payments', []);
        }

        $response = $this->postJson('/api/v1/payments', []);

        $response->assertStatus(429);
    }

    public function test_payment_status_is_rate_limited(): void
    {
        RateLimiter::clear('127.0.0.1');

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/payments/TRX-NOT-FOUND');
        }

        $response = $this->getJson('/api/v1/payments/TRX-NOT-FOUND');

        $response->assertStatus(429);
    }
}
