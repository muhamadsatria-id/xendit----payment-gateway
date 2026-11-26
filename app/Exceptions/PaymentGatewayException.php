<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentGatewayException extends RuntimeException
{
    public function __construct(string $message = "Payment gateway request failed", public readonly ?int $statusCode = null,
        ?\Throwable $previous = null,)
    {
        parent::__construct($message, 0, $previous);
    }
}