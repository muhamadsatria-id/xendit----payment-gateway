<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class Client extends Model
{
    protected $fillable = [
        'name',
        'api_key_prefix',
        'api_key_hash',
        'status',
        'callback_url',
        'callback_secret_encrypted',
        'allowed_redirect_domains',
    ];

    protected $hidden = [
        'api_key_hash',
        'callback_secret_encrypted',
    ];

    protected $casts = [
        'allowed_redirect_domains' => 'array',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public static function normalizeDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));

        if (str_contains($domain, '://')) {
            $host = parse_url($domain, PHP_URL_HOST);
            $domain = is_string($host) ? $host : '';
        }

        if ($domain === '') {
            return null;
        }

        $wildcard = str_starts_with($domain, '.');
        $domain = ltrim($domain, '.');

        if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        return $wildcard ? '.' . $domain : $domain;
    }

    public function allowDomain(string $domain): bool
    {
        $normalized = static::normalizeDomain($domain);

        if ($normalized === null) {
            return false;
        }

        $domains = $this->allowed_redirect_domains ?? [];

        if (! in_array($normalized, $domains, true)) {
            $domains[] = $normalized;
            $this->allowed_redirect_domains = $domains;
            $this->save();
        }

        return true;
    }

    public function allowsUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (
            ! is_array($parts) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            ! isset($parts['host'])
        ) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if (app()->environment('production') && $scheme !== 'https') {
            return false;
        }

        $host = strtolower(trim((string) $parts['host'], '.'));

        if ($host === '') {
            return false;
        }

        foreach ((array) ($this->allowed_redirect_domains ?? []) as $domain) {
            if (! is_string($domain)) {
                continue;
            }

            $domain = strtolower(trim($domain));

            if ($domain === '') {
                continue;
            }

            if (str_starts_with($domain, '.')) {
                $base = substr($domain, 1);

                if ($host === $base || str_ends_with($host, $domain)) {
                    return true;
                }
            } elseif ($host === $domain) {
                return true;
            }
        }

        return false;
    }

    public function decryptCallbackSecret(): ?string
    {
        if (empty($this->callback_secret_encrypted)) {
            return null;
        }

        return Crypt::decryptString($this->callback_secret_encrypted);
    }
}
