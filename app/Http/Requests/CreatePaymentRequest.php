<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:100'],
            'customer_email' => ['required', 'email', 'max:100'],
            'amount' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'currency' => ['sometimes', 'string', 'size:3', 'in:IDR'],

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
}
