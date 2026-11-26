<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use App\Exceptions\PaymentGatewayException;
use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Request as ClientRequest;
use App\Services\Payment\Providers\XenditInvoiceService;

class XenditInvoiceServiceTest extends TestCase
{
    public function test_can_create_xendit_invoice(): void
    {
        config([
            'services.xendit.secret_key' => 'xnd_test_secret',
            'services.xendit.base_url' => 'https://api.xendit.co',
        ]);

        Http::fake([
            'api.xendit.co/v2/invoices' => Http::response([
                'id' => 'inv_test_123',
                'external_id' => 'ORDER-001',
                'status' => 'PENDING',
                'invoice_url' => 'https://checkout.xendit.co/web/test',
                'expiry_date' => '2026-11-27T09:00:00.000Z',
            ], 200),
        ]);

        $provider = app(XenditInvoiceService::class);

        $result = $provider->createInvoice([
            'external_id' => 'ORDER-001',
            'amount' => 150000,
            'description' => 'Test Payment',
            'invoice_duration' => 86400,
            'currency' => 'IDR',
            'payer_email' => 'customer@example.com',
        ]);

        $this->assertEquals('inv_test_123', $result['id']);

        $this->assertEquals(
            'https://checkout.xendit.co/web/test',
            $result['invoice_url']
        );

        Http::assertSent(function (ClientRequest $request) {
            return $request->url() ===
                    'https://api.xendit.co/v2/invoices'
                && $request->method() === 'POST'
                && $request->hasHeader(
                    'Authorization',
                    'Basic ' . base64_encode('xnd_test_secret:')
                )
                && $request['external_id'] === 'ORDER-001'
                && $request['amount'] === 150000;
        });
    }

public function test_connection_timeout_is_converted_to_payment_gateway_exception(): void
{
    config([
        'services.xendit.secret_key' => 'xnd_test_secret',
        'services.xendit.base_url' => 'https://api.xendit.co',
    ]);

    Http::fake(function ($request, $options) {
        throw new ConnectionException('Connection timed out.');
    });

    $provider = app(XenditInvoiceService::class);

    $this->expectException(PaymentGatewayException::class);
    $this->expectExceptionMessage('Unable to connect to Xendit.');

    $provider->getInvoice('inv_timeout_test');
}

public function test_get_invoice_retries_twice_then_fails_on_connection_error(): void
{
    config([
        'services.xendit.secret_key' => 'xnd_test_secret',
        'services.xendit.base_url' => 'https://api.xendit.co',
    ]);

    Http::fake([
        'api.xendit.co/*' => Http::failedConnection(),
    ]);

    $provider = app(XenditInvoiceService::class);

    try {
        $provider->getInvoice('inv_retry_test');
        $this->fail('Expected PaymentGatewayException was not thrown.');
    } catch (PaymentGatewayException $e) {
        $this->assertSame(
            'Unable to connect to Xendit.',
            $e->getMessage()
        );
    }

    Http::assertSentCount(3);
}

public function test_create_invoice_is_not_retried_on_connection_error(): void
{
    config([
        'services.xendit.secret_key' => 'xnd_test_secret',
        'services.xendit.base_url' => 'https://api.xendit.co',
    ]);

    Http::fake([
        'api.xendit.co/*' => Http::failedConnection(),
    ]);

    $provider = app(XenditInvoiceService::class);

    try {
        $provider->createInvoice([
            'external_id' => 'ORDER-RETRY-001',
            'amount' => 150000,
            'currency' => 'IDR',
        ]);
        $this->fail('Expected PaymentGatewayException was not thrown.');
    } catch (PaymentGatewayException $e) {
        $this->assertSame(
            'Unable to connect to Xendit.',
            $e->getMessage()
        );
    }

    Http::assertSentCount(1);
}

public function test_can_find_invoice_by_external_id(): void
{
    config([
        'services.xendit.secret_key' => 'xnd_test_secret',
        'services.xendit.base_url' => 'https://api.xendit.co',
    ]);

    Http::fake([
        'api.xendit.co/v2/invoices*' => Http::response([
            [
                'id' => 'inv_other',
                'external_id' => 'ORDER-OTHER',
                'status' => 'PENDING',
            ],
            [
                'id' => 'inv_target',
                'external_id' => 'ORDER-TARGET',
                'status' => 'PAID',
            ],
        ], 200),
    ]);

    $provider = app(XenditInvoiceService::class);

    $invoice = $provider->findInvoiceByExternalId('ORDER-TARGET');

    $this->assertSame('inv_target', $invoice['id']);

    Http::assertSent(function (ClientRequest $request) {
        return $request->method() === 'GET'
            && $request->url() ===
                'https://api.xendit.co/v2/invoices?external_id=ORDER-TARGET';
    });
}

public function test_can_expire_invoice(): void
{
    config([
        'services.xendit.secret_key' => 'xnd_test_secret',
        'services.xendit.base_url' => 'https://api.xendit.co',
    ]);

    Http::fake([
        'api.xendit.co/invoices/inv_expire_001/expire!' => Http::response([
            'id' => 'inv_expire_001',
            'external_id' => 'ORDER-EXPIRE-001',
            'status' => 'EXPIRED',
        ], 200),
    ]);

    $provider = app(XenditInvoiceService::class);

    $invoice = $provider->expireInvoice('inv_expire_001');

    $this->assertSame('EXPIRED', $invoice['status']);

    Http::assertSent(function (ClientRequest $request) {
        return $request->method() === 'POST'
            && $request->url() ===
                'https://api.xendit.co/invoices/inv_expire_001/expire!';
    });
}

}
