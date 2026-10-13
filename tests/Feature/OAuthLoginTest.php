<?php

use App\Services\TokenRefresher;
use App\Services\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('starts the authorization code flow without prompting when the app has a secret', function () {
    $process = runFic(
        ['auth:login', '--no-browser', '--timeout=1'],
        ['FIC_CLIENT_ID' => 'builtin-client', 'FIC_CLIENT_SECRET' => 'builtin-secret'],
    );

    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(1)
        ->and($output)
        ->toContain('https://api-v2.fattureincloud.it/oauth/authorize?')
        ->toContain('client_id=builtin-client')
        ->toContain(urlencode('http://localhost:8511/callback'))
        ->toContain('fic auth:login --token=')
        ->not->toContain('builtin-secret')
        ->not->toContain('How would you like to authenticate');
});

it('ships a built-in public OAuth app without a client secret', function () {
    expect(config('oauth.client_id'))->not->toBeEmpty()
        ->and(config('oauth.client_secret'))->toBeEmpty();
});

it('refreshes tokens of the built-in app without a stored secret', function () {
    $configDir = sys_get_temp_dir().'/fic-tests-'.bin2hex(random_bytes(6));
    mkdir($configDir, 0700, true);
    TokenStore::setConfigDir($configDir);

    config(['oauth.client_id' => 'builtin-client', 'oauth.client_secret' => 'builtin-secret']);

    TokenStore::save([
        'access_token' => 'old-token',
        'refresh_token' => 'refresh-me',
        'client_id' => 'builtin-client',
        'expires_at' => time() - 10,
    ]);

    Http::fake([
        'https://api-v2.fattureincloud.it/oauth/token' => Http::response([
            'access_token' => 'new-token',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
        ]),
    ]);

    expect(TokenRefresher::freshAccessToken())->toBe('new-token');

    Http::assertSent(fn (Request $request) => $request['client_secret'] === 'builtin-secret'
        && $request['refresh_token'] === 'refresh-me');

    expect(TokenStore::load())->not->toHaveKey('client_secret');
});
