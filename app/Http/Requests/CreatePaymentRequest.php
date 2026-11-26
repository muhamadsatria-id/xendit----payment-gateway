<?php

namespace App\Http\Requests;

use App\Models\Client;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:100'],
            'customer_email' => ['required', 'email', 'max:100'],
            'amount' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'currency' => ['sometimes', 'string', 'size:3', 'in:IDR'],

            'success_redirect_url' => [
                'sometimes',
                'nullable',
                'url',
                'max:2048',
                fn ($attribute, $value, $fail) =>
                    $this->validateRedirectUrl($attribute, $value, $fail),
            ],
            'failure_redirect_url' => [
                'sometimes',
                'nullable',
                'url',
                'max:2048',
                fn ($attribute, $value, $fail) =>
                    $this->validateRedirectUrl($attribute, $value, $fail),
            ],

            // Idempotency key divalidasi dari header.
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    private function validateRedirectUrl(
        string $attribute,
        mixed $value,
        Closure $fail
    ): void {
        if (! is_string($value) || $value === '') {
            return;
        }

        $client = $this->attributes->get('client');

        if (
            ! $client instanceof Client ||
            ! $client->allowsUrl($value)
        ) {
            $fail(
                'The ' . str_replace('_', ' ', $attribute) .
                ' host is not whitelisted for this client.'
            );
        }
    }
}
