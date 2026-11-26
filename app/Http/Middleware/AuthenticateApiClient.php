<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiClient
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key');

        if (
            ! is_string($apiKey) ||
            strlen($apiKey) < 32 ||
            strlen($apiKey) > 128
        ) {
            return $this->unauthorized();
        }

        // Data store hanya menyimpan hash; plaintext tidak pernah dipakai selain di sini.
        $hash = hash('sha256', $apiKey);

        $client = Client::query()
            ->where('api_key_hash', $hash)
            ->where('status', 'active')
            ->first();

        if (
            ! $client ||
            ! hash_equals($client->api_key_hash, $hash)
        ) {
            return $this->unauthorized();
        }

        $request->attributes->set('client', $client);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Invalid or missing API key.',
        ], 401);
    }
}
