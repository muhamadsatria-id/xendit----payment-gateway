<?php

namespace Tests;

use App\Models\Client;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected ?string $apiKey = null;

    protected function authenticateApiClient(string $name = 'Test Client'): Client
    {
        $apiKey = 'pgk_' . Str::random(64);

        $client = Client::create([
            'name' => $name,
            'api_key_prefix' => substr($apiKey, 0, 12),
            'api_key_hash' => hash('sha256', $apiKey),
            'status' => 'active',
        ]);

        $this->apiKey = $apiKey;

        return $client;
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    protected function apiHeaders(array $extra = []): array
    {
        return array_merge(
            ['X-API-Key' => $this->apiKey ?? ''],
            $extra
        );
    }

    protected function configureClientCallback(
        Client $client,
        string $url,
        ?string $secret = null
    ): string {
        $secret ??= 'whsec_' . Str::random(48);

        $host = parse_url($url, PHP_URL_HOST);
        $domains = $client->allowed_redirect_domains ?? [];

        if (is_string($host) && ! in_array($host, $domains, true)) {
            $domains[] = $host;
        }

        $client->update([
            'callback_url' => $url,
            'callback_secret_encrypted' => Crypt::encryptString($secret),
            'allowed_redirect_domains' => $domains,
        ]);

        return $secret;
    }
}
