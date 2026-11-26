<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IdempotencyKeyConflictException;
use App\Exceptions\PaymentRequestInProgressException;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePaymentRequest;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use App\Models\Transaction;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    public function store(
        CreatePaymentRequest $request
    ): JsonResponse {
        try {
            $result = $this->paymentService->createPayment(
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment created successfully.',
                'data' => [
                    'status_token' => $result['status_token'],
                    'idempotent_replay' => $result['replayed'],
                    'transaction_number' =>
                        $result['transaction']->transaction_number,

                    'amount' =>
                        $result['payment']->amount,

                    'currency' =>
                        $result['payment']->currency,

                    'status' =>
                        $result['payment']->status,

                    'payment_url' =>
                        $result['payment']->invoice_url,
                ],
            ], $result['replayed'] ? 200 : 201);
        } catch (IdempotencyKeyConflictException $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 409);

    } catch (PaymentRequestInProgressException $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 409);
        } catch (PaymentGatewayException $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create payment. Please check payment status before retrying.',
            ], 502);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'An internal error occurred.',
            ], 500);
        }
    }
    public function show(
    Request $request,
    string $transactionNumber
): JsonResponse {
    // 1. Cari transaksi terlebih dahulu.
    $transaction = Transaction::with([
        'payments' => fn ($query) => $query->latest('id'),
    ])
        ->where('transaction_number', $transactionNumber)
        ->first();

    // 2. Jika tidak ditemukan, kembalikan 404.
    if (! $transaction) {
        return response()->json([
            'success' => false,
            'message' => 'Transaction not found.',
        ], 404);
    }

    // 3. Verifikasi token setelah transaksi ditemukan.
    $statusToken = $request->header('X-Status-Token');

    if (
        ! is_string($statusToken) ||
        $statusToken === '' ||
        empty($transaction->status_token_hash) ||
        ! hash_equals(
            $transaction->status_token_hash,
            hash('sha256', $statusToken)
        )
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid or missing status token.',
        ], 401);
    }

    // 4. Lanjutkan response status pembayaran yang sudah ada.

    $payment = $transaction->payments->first();

    return response()->json([
        'success' => true,
        'data' => [
            'transaction_number' => $transaction->transaction_number,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status,
            'paid_at' => $transaction->paid_at,
            'payment' => $payment ? [
                'provider' => $payment->provider,
                'status' => $payment->status,
                'payment_url' => $payment->invoice_url,
                'expired_at' => $payment->expired_at,
                'paid_at' => $payment->paid_at,
                
            ] : null,
        ],
    ]);
}
}
