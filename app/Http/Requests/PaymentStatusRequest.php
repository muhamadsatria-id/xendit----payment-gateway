<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PaymentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'transaction_number' => [
                'required',
                'string',
                'max:100',
                'regex:/^TRX-[A-Z0-9-]+$/',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'transaction_number' => $this->route('transactionNumber'),
        ]);
    }
}
