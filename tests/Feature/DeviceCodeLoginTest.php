<?php

use App\Services\TokenRefresher;
use App\Services\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

beforeEach(function () {
    $configDir = sys_get_temp_dir().'/fic-tests-'.bin2hex(random_bytes(6));
    mkdir($configDir, 0700, true);
    TokenStore::setConfigDir($configDir);

    // An app without a client secret signs in with the device code flow.
    config(['oauth.client_id' => 'public-client', 'oauth.client_secret' => '']);
});

function fakeDeviceCodeApi(array $tokenResponses): void
{
    Http::fake([
        'https://api-v2.fattureincloud.it/oauth/device' => Http::response(['data' => [
            'device_code' => 'secret-device-code',
            'user_code' => 'ABCD-1234',
            'scope' => 'entity.clients:r',
            'verification_uri' => 'https://fattureincloud.it/connetti',
            'interval' => 1,
            'expires_in' => 300,
        ]]),
        'https://api-v2.fattureincloud.it/oauth/token' => Http::sequence($tokenResponses),
        'https://api-v2.fattureincloud.it/user/info' => Http::response(['data' => [
            'name' => 'Mario', 'surname' => 'Rossi', 'email' => 'mario@example.com',
        ]]),
        'https://api-v2.fattureincloud.it/user/companies' => Http::response(['data' => ['companies' => [
            ['id' => 42, 'name' => 'Rossi Srl'],
        ]]]),
    ]);
}

it('signs in with the device code flow when the app has no client secret', function () {
    /** @var TestCase $this */
    fakeDeviceCodeApi([
        Http::response(['error' => 'authorization_pending'], 400),
        Http::response(['access_token' => 'device-token', 'refresh_token' => 'device-refresh', 'token_type' => 'bearer', 'expires_in' => 86400]),
    ]);

    $this->artisan('auth:login', ['--no-browser' => true])
        ->expectsOutputToContain('https://fattureincloud.it/connetti')
        ->expectsOutputToContain('ABCD-1234')
        ->expectsOutputToContain('Authenticated as Mario Rossi (mario@example.com)')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api-v2.fattureincloud.it/oauth/device'
        && $request['client_id'] === 'public-client'
        && str_contains($request['scope'], 'entity.clients:r')
        && ! str_contains($request['scope'], ':a'));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api-v2.fattureincloud.it/oauth/token'
        && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:device_code'
        && $request['device_code'] === 'secret-device-code'
        && ! isset($request['client_secret']));

    $auth = TokenStore::load();

    expect($auth['access_token'])->toBe('device-token')
        ->and($auth['refresh_token'])->toBe('device-refresh')
        ->and($auth['client_id'])->toBe('public-client')
        ->and($auth['scopes'])->toContain('entity.clients:r')
        ->and($auth['client_secret'] ?? null)->toBeNull()
        ->and(TokenStore::getCompanyId())->toBe(42);
});

it('stops when the user denies access', function () {
    /** @var TestCase $this */
    fakeDeviceCodeApi([
        Http::response(['error' => 'access_denied'], 400),
    ]);

    $this->artisan('auth:login', ['--no-browser' => true])
        ->expectsOutputToContain('denied')
        ->assertFailed();

    expect(TokenStore::isAuthenticated())->toBeFalse();
});

it('refreshes device code tokens without a client secret', function () {
    TokenStore::save([
        'access_token' => 'old-token',
        'refresh_token' => 'refresh-me',
        'client_id' => 'public-client',
        'expires_at' => time() - 10,
    ]);

    Http::fake([
        'https://api-v2.fattureincloud.it/oauth/token' => Http::response([
            'access_token' => 'new-token',
            'refresh_token' => 'new-refresh',
            'expires_in' => 86400,
        ]),
    ]);

    expect(TokenRefresher::freshAccessToken())->toBe('new-token');

    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'refresh_token'
        && $request['client_id'] === 'public-client'
        && ! isset($request['client_secret']));
});
