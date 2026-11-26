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
        RateLimiter::for('payment-create', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip());
        });

        RateLimiter::for('payment-status', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->ip());
        });
    }
}
