<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use App\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Providers\XenditInvoiceService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            PaymentGatewayInterface::class,
            XenditInvoiceService::class
        );
    }

    public function boot(): void
    {
        $clientKey = function (Request $request): string {
            $client = $request->attributes->get('client');

            return $client
                ? 'client:' . $client->id
                : 'ip:' . $request->ip();
        };

        RateLimiter::for('payment-create', function (Request $request) use ($clientKey) {
            return Limit::perMinute(5)
                ->by($clientKey($request));
        });

        RateLimiter::for('payment-status', function (Request $request) use ($clientKey) {
            return Limit::perMinute(30)
                ->by($clientKey($request));
        });

        RateLimiter::for('webhook', function (Request $request) {
            return Limit::perMinute(100)
                ->by('ip:' . $request->ip());
        });
    }
}
