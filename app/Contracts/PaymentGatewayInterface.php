<?php

namespace App\Contracts;

interface PaymentGatewayInterface
{
    public function createInvoice(array $payload): array;

    public function getInvoice(string $invoiceId): array;

    public function findInvoiceByExternalId(string $externalId): ?array;

    public function expireInvoice(string $invoiceId): array;
}
