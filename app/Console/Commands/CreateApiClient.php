<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('clients:create {name : Client display name}')]
#[Description('Create a client and print its API key once.')]
class CreateApiClient extends Command
{
    public function handle(): int
    {
        $apiKey = 'pgk_' . Str::random(64);

        $client = Client::create([
            'name' => (string) $this->argument('name'),
            'api_key_prefix' => substr($apiKey, 0, 12),
            'api_key_hash' => hash('sha256', $apiKey),
            'status' => 'active',
        ]);

        $this->info('API key created. Copy it now; it is shown only once.');
        $this->line($apiKey);
        $this->line('Client ID: ' . $client->id);

        return self::SUCCESS;
    }
}
