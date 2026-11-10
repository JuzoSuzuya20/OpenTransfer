<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Tells an admin, before anyone tries to sign in, whether an OpenID Connect provider answers at
 * the address given, and whether it recognises the client and its secret.
 */
class OidcConnectionTest
{
    /** @return array{discovery: string, client: string, logout: bool, issuer: ?string} */
    public function run(string $issuer, string $clientId, string $clientSecret): array
    {
        $expected = rtrim($issuer, '/');
        $result = ['discovery' => 'failed', 'client' => 'unknown', 'logout' => false, 'issuer' => null];

        try {
            $response = Http::timeout(5)->withoutRedirecting()->acceptJson()->get("$expected/.well-known/openid-configuration");
        } catch (ConnectionException) {
            return $result;
        }

        $document = $response->successful() ? $response->json() : null;
        if (!is_array($document) || empty($document['issuer']) || empty($document['token_endpoint'])) {
            return $result;
        }

        $result['issuer'] = $document['issuer'];
        // A provider that names another issuer would sign tokens the library refuses
        if (rtrim($document['issuer'], '/') !== $expected) {
            $result['discovery'] = 'issuer_mismatch';
            return $result;
        }

        $result['discovery'] = 'ok';
        $result['logout'] = !empty($document['end_session_endpoint']);
        $result['client'] = $this->checkClient($document['token_endpoint'], $clientId, $clientSecret);

        return $result;
    }

    // A client credentials request: refused for lack of a service account, the client was still recognised
    private function checkClient(string $tokenEndpoint, string $clientId, string $clientSecret): string
    {
        try {
            $response = Http::timeout(5)->withoutRedirecting()->asForm()->acceptJson()
                ->withBasicAuth($clientId, $clientSecret)
                ->post($tokenEndpoint, ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            return 'unknown';
        }

        if ($response->successful()) {
            return 'ok';
        }

        $error = $response->json('error');
        if ($error === 'invalid_client' || $response->status() === 401) {
            return 'invalid';
        }

        return in_array($error, ['unauthorized_client', 'unsupported_grant_type', 'invalid_grant', 'invalid_scope'], true) ? 'ok' : 'unknown';
    }
}
