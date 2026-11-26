<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'transaction_id',
        'provider',
        'external_id',
        'gateway_invoice_id',
        'invoice_url',
        'amount',
        'currency',
        'status',
        'expired_at',
        'paid_at',
        'gateway_response',
    ];
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'expired_at' => 'datetime',
            'paid_at' => 'datetime',
            'gateway_response' => 'array',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PaymentLog::class);
    }
}
