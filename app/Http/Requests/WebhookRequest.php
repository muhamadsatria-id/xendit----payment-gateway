<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

class WebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expected = config('services.xendit.webhook_token');
        $received = $this->header('x-callback-token');

        return is_string($expected) &&
            $expected !== '' &&
            is_string($received) &&
            $received !== '' &&
            hash_equals($expected, $received);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:100'],
            'webhook_id' => ['sometimes', 'string', 'max:100'],
            'external_id' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'max:20'],
            'amount' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Invalid webhook token.',
        ], Response::HTTP_UNAUTHORIZED));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Invoice ID is required.',
            'errors' => $validator->errors(),
        ], Response::HTTP_BAD_REQUEST));
    }
}
