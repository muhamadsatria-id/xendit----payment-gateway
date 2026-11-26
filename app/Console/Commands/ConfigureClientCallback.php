<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

#[Signature('clients:configure-callback {client : Client ID} {url : HTTPS callback URL}')]
#[Description('Set a client callback URL and print a new HMAC secret once.')]
class ConfigureClientCallback extends Command
{
    public function handle(): int
    {
        $client = Client::findOrFail((int) $this->argument('client'));
        $url = (string) $this->argument('url');

        if (! $client->allowsUrl($url)) {
            $this->error(
                'URL tidak diizinkan. Whitelist domain terlebih dahulu via clients:allow-domain.'
            );

            return self::FAILURE;
        }

        $secret = 'whsec_' . Str::random(48);

        $client->update([
            'callback_url' => $url,
            'callback_secret_encrypted' => Crypt::encryptString($secret),
        ]);

        $this->info('Callback secret (hanya ditampilkan sekali):');
        $this->line($secret);
        $this->line('Callback URL: ' . $url);

        return self::SUCCESS;
    }
}
