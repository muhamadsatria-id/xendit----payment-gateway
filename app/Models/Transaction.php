<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    protected $fillable = [
        'transaction_number',
        'customer_name',
        'customer_email',
        'amount',
        'currency',
        'status',
        'paid_at',
        'idempotency_key',
        'request_hash',
        'status_token_hash',
        'status_token_encrypted',
    ];

    protected $hidden = [
        'status_token_hash',
        'status_token_encrypted',
        'idempotency_key',
        'request_hash',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}