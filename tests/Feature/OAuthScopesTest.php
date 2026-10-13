<?php

use App\Services\TokenStore;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

const BUILTIN_APP = ['FIC_CLIENT_ID' => 'builtin-client', 'FIC_CLIENT_SECRET' => 'builtin-secret'];

/**
 * Scopes requested by `auth:login`/`auth:grant`, read from the printed authorization URL.
 */
function requestedScopes(string $output): array
{
    preg_match('#https://api-v2\.fattureincloud\.it/oauth/authorize\?\S+#', $output, $matches);
    parse_str(parse_url($matches[0] ?? '', PHP_URL_QUERY) ?? '', $query);

    return explode(' ', $query['scope'] ?? '');
}

function signInWithScopes(?array $scopes): void
{
    $configDir = sys_get_temp_dir().'/fic-tests-'.bin2hex(random_bytes(6));
    mkdir($configDir, 0700, true);
    TokenStore::setConfigDir($configDir);

    TokenStore::save(array_filter([
        'access_token' => 'test-token',
        'client_id' => 'builtin-client',
        'scopes' => $scopes,
    ], fn ($value) => $value !== null));
}

it('asks for read-only access by default', function () {
    $scopes = requestedScopes(runFic(['auth:login', '--no-browser', '--timeout=1'], BUILTIN_APP)->getOutput());

    expect($scopes)
        ->toContain('entity.clients:r', 'issued_documents.invoices:r', 'settings:r')
        ->and(collect($scopes)->filter(fn ($scope) => str_ends_with($scope, ':a'))->all())->toBe([]);
});

it('adds extra permissions requested at login', function () {
    $scopes = requestedScopes(runFic(
        ['auth:login', '--no-browser', '--timeout=1', '--grant=issued_documents.invoices:a,entity.clients:a'],
        BUILTIN_APP,
    )->getOutput());

    expect($scopes)
        ->toContain('entity.suppliers:r', 'issued_documents.invoices:a', 'entity.clients:a')
        ->not->toContain('entity.suppliers:a');
});

it('asks for full access with --full', function () {
    $scopes = requestedScopes(runFic(['auth:login', '--no-browser', '--timeout=1', '--full'], BUILTIN_APP)->getOutput());

    expect($scopes)->toContain('entity.clients:a', 'issued_documents.invoices:a', 'settings:a');
});

it('rejects unknown permissions', function () {
    $process = runFic(['auth:login', '--no-browser', '--timeout=1', '--grant=invoices:write'], BUILTIN_APP);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput().$process->getErrorOutput())
        ->toContain('invoices:write')
        ->not->toContain('oauth/authorize');
});

it('grants extra permissions on top of the current ones', function () {
    $configHome = sys_get_temp_dir().'/fic-tests-'.bin2hex(random_bytes(6));
    mkdir($configHome.'/fattureincloud-cli', 0700, true);
    file_put_contents($configHome.'/fattureincloud-cli/auth.json', json_encode([
        'access_token' => 'x',
        'client_id' => 'builtin-client',
        'scopes' => ['entity.clients:r', 'archive:a'],
    ]));

    $scopes = requestedScopes(runFic(
        ['auth:grant', 'issued_documents.invoices:a', '--no-browser', '--timeout=1'],
        BUILTIN_APP,
        $configHome,
    )->getOutput());

    expect($scopes)->toEqualCanonicalizing(['entity.clients:r', 'archive:a', 'issued_documents.invoices:a']);
});

it('stops an API call that needs a permission not granted yet', function () {
    /** @var TestCase $this */
    signInWithScopes(['entity.clients:r']);
    Http::fake();

    $this->artisan('api:create-client', ['--company-id' => 1, '--input' => '{"data":{"name":"Acme"}}'])
        ->expectsOutputToContain('fic auth:grant entity.clients:a')
        ->assertFailed();

    Http::assertNothingSent();
});

it('lets an API call through when the permission is granted', function () {
    /** @var TestCase $this */
    signInWithScopes(['entity.clients:a']);
    Http::fake(['*' => Http::response(['data' => []])]);

    // Full access also covers reading.
    $this->artisan('api:list-clients', ['--company-id' => 1])->assertSuccessful();

    Http::assertSentCount(1);
});

it('checks the permission of the requested document type', function () {
    /** @var TestCase $this */
    signInWithScopes(['issued_documents.invoices:r']);
    Http::fake(['*' => Http::response(['data' => []])]);

    $this->artisan('api:list-issued-documents', ['--company-id' => 1, '--type' => 'quote'])
        ->expectsOutputToContain('fic auth:grant issued_documents.quotes:r')
        ->assertFailed();

    $this->artisan('api:list-issued-documents', ['--company-id' => 1, '--type' => 'invoice'])
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('does not block calls when the granted permissions are unknown', function () {
    /** @var TestCase $this */
    signInWithScopes(null);
    Http::fake(['*' => Http::response(['data' => []])]);

    $this->artisan('api:create-client', ['--company-id' => 1, '--input' => '{"data":{"name":"Acme"}}'])
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('shows granted permissions in auth:status', function () {
    /** @var TestCase $this */
    signInWithScopes(['entity.clients:r', 'archive:a']);

    $this->artisan('auth:status', ['--json' => true])
        ->expectsOutputToContain('"archive:a"')
        ->assertSuccessful();
});
