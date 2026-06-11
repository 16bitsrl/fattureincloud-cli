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
        $clientSecret = $auth['client_secret'] ?? null;

        if (! $refreshToken || ! $clientId || ! $clientSecret) {
            return $token;
        }

        try {
            $response = Http::post('https://api-v2.fattureincloud.it/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ]);
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
}
