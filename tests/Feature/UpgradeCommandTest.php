<?php

use App\Services\Installation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

const RELEASES = 'https://github.com/16bitsrl/fattureincloud-cli/releases/download';

function installedBinary(string $contents = 'old-binary'): string
{
    $dir = sys_get_temp_dir().'/fic-upgrade-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/fic', $contents);
    chmod($dir.'/fic', 0755);

    return $dir.'/fic';
}

function fakeRelease(string $version, string $binary, ?string $checksum = null): void
{
    Http::fake([
        'https://api.github.com/repos/16bitsrl/fattureincloud-cli/releases/latest' => Http::response(['tag_name' => 'v2.0.0']),
        RELEASES."/v{$version}/fic-macos-aarch64" => Http::response($binary),
        RELEASES."/v{$version}/checksums.txt" => Http::response(
            ($checksum ?? hash('sha256', $binary))."  fic-macos-aarch64\n".hash('sha256', 'other')."  fic\n",
        ),
        '*' => Http::response('', 404),
    ]);
}

beforeEach(function () {
    config(['app.version' => '1.3.0']);
});

it('replaces the binary with the latest release', function () {
    /** @var TestCase $this */
    $path = installedBinary();
    $this->app->instance(Installation::class, new Installation($path, 'fic-macos-aarch64'));
    fakeRelease('2.0.0', 'new-binary');

    $this->artisan('upgrade')
        ->expectsOutputToContain('2.0.0')
        ->assertSuccessful();

    expect(file_get_contents($path))->toBe('new-binary')
        ->and(fileperms($path) & 0777)->toBe(0755)
        ->and(glob(dirname($path).'/*'))->toBe([$path]);
});

it('installs the requested version', function () {
    /** @var TestCase $this */
    $path = installedBinary();
    $this->app->instance(Installation::class, new Installation($path, 'fic-macos-aarch64'));
    fakeRelease('1.2.0', 'older-binary');

    $this->artisan('upgrade', ['version' => 'v1.2.0'])->assertSuccessful();

    expect(file_get_contents($path))->toBe('older-binary');
});

it('keeps the current binary when the download does not match its checksum', function () {
    /** @var TestCase $this */
    $path = installedBinary();
    $this->app->instance(Installation::class, new Installation($path, 'fic-macos-aarch64'));
    fakeRelease('2.0.0', 'tampered-binary', checksum: hash('sha256', 'new-binary'));

    $this->artisan('upgrade')
        ->expectsOutputToContain('checksum')
        ->assertFailed();

    expect(file_get_contents($path))->toBe('old-binary')
        ->and(glob(dirname($path).'/*'))->toBe([$path]);
});

it('does nothing when already up to date', function () {
    /** @var TestCase $this */
    config(['app.version' => '2.0.0']);
    $path = installedBinary();
    $this->app->instance(Installation::class, new Installation($path, 'fic-macos-aarch64'));
    fakeRelease('2.0.0', 'new-binary');

    $this->artisan('upgrade')
        ->expectsOutputToContain('up to date')
        ->assertSuccessful();

    expect(file_get_contents($path))->toBe('old-binary');
    Http::assertSentCount(1);
});

it('only reports the new version with --check', function () {
    /** @var TestCase $this */
    $path = installedBinary();
    $this->app->instance(Installation::class, new Installation($path, 'fic-macos-aarch64'));
    fakeRelease('2.0.0', 'new-binary');

    $this->artisan('upgrade', ['--check' => true])
        ->expectsOutputToContain('2.0.0')
        ->assertSuccessful();

    expect(file_get_contents($path))->toBe('old-binary');
    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), RELEASES));
});

it('leaves Composer installs to Composer', function () {
    /** @var TestCase $this */
    $path = installedBinary();
    $this->app->instance(Installation::class, new Installation($path, null, Installation::COMPOSER));
    fakeRelease('2.0.0', 'new-binary');

    $this->artisan('upgrade')
        ->expectsOutputToContain('composer global update 16bitsrl/fattureincloud-cli')
        ->assertFailed();

    expect(file_get_contents($path))->toBe('old-binary');
});

it('picks the release file for the current platform', function () {
    expect(Installation::binaryAsset('Darwin', 'arm64'))->toBe('fic-macos-aarch64')
        ->and(Installation::binaryAsset('Darwin', 'x86_64'))->toBe('fic-macos-x86_64')
        ->and(Installation::binaryAsset('Linux', 'aarch64'))->toBe('fic-linux-aarch64')
        ->and(Installation::binaryAsset('Linux', 'amd64'))->toBe('fic-linux-x86_64')
        ->and(Installation::binaryAsset('Windows', 'AMD64'))->toBe('fic-windows-x86_64.exe')
        ->and(Installation::binaryAsset('Linux', 'riscv64'))->toBeNull();
});
