<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clients:allow-domain {client : Client ID} {domain : Domain atau wildcard, mis. app.example.com atau .example.com}')]
#[Description('Whitelist a redirect/callback domain for a client.')]
class AllowClientDomain extends Command
{
    public function handle(): int
    {
        $client = Client::findOrFail((int) $this->argument('client'));
        $domain = (string) $this->argument('domain');

        if (! $client->allowDomain($domain)) {
            $this->error('Domain tidak valid.');

            return self::FAILURE;
        }

        $this->info('Domain diizinkan: ' . Client::normalizeDomain($domain));

        return self::SUCCESS;
    }
}
