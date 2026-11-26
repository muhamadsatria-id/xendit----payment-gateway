<?php

namespace App\Services\Payment\Providers;

use App\Contracts\PaymentGatewayInterface;
use App\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class XenditInvoiceService implements PaymentGatewayInterface
{
    protected string $baseUrl;
    protected string $secretKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            config('services.xendit.base_url'),
            '/'
        );

        $this->secretKey = config('services.xendit.secret_key');

        if (empty($this->secretKey)) {
            throw new PaymentGatewayException(
                'Xendit secret key is not configured.'
            );
        }
    }

    public function createInvoice(array $payload): array
    {
        return $this->sendRequest(function () use ($payload) {
            return Http::withBasicAuth($this->secretKey, '')
                ->acceptJson()
                ->asJson()
                ->connectTimeout(3)
                ->timeout(15)
                ->post($this->baseUrl . '/v2/invoices', $payload);
        });
    }

    public function getInvoice(string $invoiceId): array
    {
        return $this->sendRequest(function () use ($invoiceId) {
            return $this->getRequest()->get(
                $this->baseUrl . '/v2/invoices/' .
                rawurlencode($invoiceId)
            );
        });
    }

    public function findInvoiceByExternalId(string $externalId): ?array
    {
        $invoices = $this->sendRequest(function () use ($externalId) {
            return $this->getRequest()->get(
                $this->baseUrl . '/v2/invoices',
                ['external_id' => $externalId]
            );
        });

        if (! is_array($invoices)) {
            return null;
        }

        foreach ($invoices as $invoice) {
            if (
                is_array($invoice) &&
                ($invoice['external_id'] ?? null) === $externalId
            ) {
                return $invoice;
            }
        }

        return null;
    }

    public function expireInvoice(string $invoiceId): array
    {
        return $this->sendRequest(function () use ($invoiceId) {
            return Http::withBasicAuth($this->secretKey, '')
                ->acceptJson()
                ->asJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->post(
                    $this->baseUrl . '/invoices/' .
                    rawurlencode($invoiceId) . '/expire!'
                );
        });
    }

    private function getRequest(): PendingRequest
    {
        return Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->retry(3, 100, function (Throwable $e) {
                return $e instanceof ConnectionException ||
                    ($e instanceof RequestException &&
                        ($e->response->serverError() ||
                            $e->response->status() === 429));
            }, false);
    }

    private function sendRequest(callable $request): array
    {
        try {
            $response = $request();

            if ($response->failed()) {
                throw new PaymentGatewayException(
                    'Xendit API returned an unsuccessful response.',
                    $response->status()
                );
            }

            return $response->json() ?? [];

        } catch (PaymentGatewayException $e) {
            throw $e;

        } catch (ConnectionException $e) {
            throw new PaymentGatewayException(
                'Unable to connect to Xendit.',
                null,
                $e
            );

        } catch (Throwable $e) {
            throw new PaymentGatewayException(
                'Unexpected Xendit integration error.',
                null,
                $e
            );
        }
    }
}
