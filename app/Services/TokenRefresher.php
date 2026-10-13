<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class TokenRefresher
{
    /**
     * Return the stored access token, refreshing it first when expired.
     *
     * Falls back to the stored token when no refresh is possible so the
     * request can still run (and fail with a clear 401 if truly expired).
     */
    public static function freshAccessToken(): ?string
    {
        $auth = TokenStore::load();
        $token = $auth['access_token'] ?? null;
        $expiresAt = $auth['expires_at'] ?? null;

        if (! $token || ! $expiresAt || time() < (int) $expiresAt - 60) {
            return $token;
        }

        $refreshToken = $auth['refresh_token'] ?? null;
        $clientId = $auth['client_id'] ?? null;
        $clientSecret = static::clientSecret($auth);

        if (! $refreshToken || ! $clientId) {
            return $token;
        }

        try {
            $response = Http::post('https://api-v2.fattureincloud.it/oauth/token', static::refreshPayload($clientId, $clientSecret, $refreshToken));
        } catch (Throwable) {
            return $token;
        }

        if ($response->failed()) {
            return $token;
        }

        $data = $response->json();

        TokenStore::save([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_in' => $data['expires_in'] ?? null,
            // Cleared so a response without expires_in cannot leave a stale
            // timestamp that would re-trigger a refresh on every request.
            'expires_at' => null,
        ]);

        return $data['access_token'];
    }

    /**
     * Device code logins (public clients) refresh without a client secret.
     */
    public static function refreshPayload(string $clientId, ?string $clientSecret, string $refreshToken): array
    {
        return array_filter([
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * The client secret for the stored login: the user's own app stores its
     * secret in auth.json, the built-in app reads it from config.
     */
    public static function clientSecret(array $auth): ?string
    {
        if (! empty($auth['client_secret'])) {
            return $auth['client_secret'];
        }

        $clientId = $auth['client_id'] ?? null;

        return $clientId && $clientId === config('oauth.client_id')
            ? (config('oauth.client_secret') ?: null)
            : null;
    }
}
