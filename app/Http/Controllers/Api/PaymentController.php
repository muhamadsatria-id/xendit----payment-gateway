<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ClientResourceNotFoundException;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\PaymentRequestInProgressException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePaymentRequest;
use App\Http\Requests\PaymentStatusRequest;
use App\Models\Transaction;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    public function store(CreatePaymentRequest $request): JsonResponse
    {
        try {
            $result = $this->paymentService->createPayment(
                $request->validated(),
                (int) $request->attributes->get('client')->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment created successfully.',
                'data' => [
                    'status_token' => $result['status_token'],
                    'idempotent_replay' => $result['replayed'],
                    'transaction_number' =>
                        $result['transaction']->transaction_number,
                    'amount' => $result['payment']->amount,
                    'currency' => $result['payment']->currency,
                    'status' => $result['payment']->status,
                    'payment_url' => $result['payment']->invoice_url,
                    'success_redirect_url' =>
                        $result['transaction']->success_redirect_url,
                    'failure_redirect_url' =>
                        $result['transaction']->failure_redirect_url,
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
        } catch (ClientResourceNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found.',
            ], 404);
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

    public function show(PaymentStatusRequest $request): JsonResponse
    {
        $transaction = Transaction::with([
            'payments' => fn ($query) => $query->latest('id'),
        ])
            ->where('client_id', (int) $request->attributes->get('client')->id)
            ->where(
                'transaction_number',
                $request->validated()['transaction_number']
            )
            ->first();

        if (! $transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found.',
            ], 404);
        }

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
