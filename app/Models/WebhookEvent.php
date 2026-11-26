<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'provider_event_id',
        'payload',
        'status',
        'result',
        'attempts',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'attempts' => 'integer',
    ];
}
