<?php

namespace App\Commands\Auth;

use App\Services\TokenStore;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

class RefreshCommand extends Command
{
    protected $signature = 'auth:refresh';

    protected $description = 'Refresh the OAuth2 access token using the stored refresh token';

    public function handle(): int
    {
        $refreshToken = TokenStore::getRefreshToken();
        $clientId = TokenStore::getClientId();
        $clientSecret = TokenStore::getClientSecret();

        if (! $refreshToken || ! $clientId || ! $clientSecret) {
            $this->error('No refresh token available. Re-authenticate with: fic auth:login');

            return self::FAILURE;
        }

        $this->info('Refreshing access token...');

        $response = Http::post('https://api-v2.fattureincloud.it/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            $this->error('Token refresh failed: '.$response->json('error_description', 'Unknown error'));
            $this->line('Re-authenticate with: fic auth:login');

            return self::FAILURE;
        }

        $data = $response->json();

        TokenStore::save([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_in' => $data['expires_in'] ?? null,
        ]);

        $this->info('Access token refreshed successfully.');

        return self::SUCCESS;
    }
}
