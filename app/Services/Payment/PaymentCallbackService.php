<?php

namespace App\Services\Payment;

use App\Jobs\SendPaymentCallback;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class PaymentCallbackService
{
    public function dispatchForTransaction(Transaction $transaction): bool
    {
        $payment = $transaction->payments()
            ->latest('id')
            ->first();

        $status = $transaction->status;

        if ($status === 'PENDING' && $payment?->status === 'FAILED') {
            $status = 'FAILED';
        }

        if (! in_array($status, ['PAID', 'EXPIRED', 'FAILED'], true)) {
            return false;
        }

        if ($transaction->callback_dispatched_at !== null) {
            return false;
        }

        $claimed = Transaction::whereKey($transaction->id)
            ->whereNull('callback_dispatched_at')
            ->update(['callback_dispatched_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        SendPaymentCallback::dispatch($transaction->id);

        return true;
    }
}
