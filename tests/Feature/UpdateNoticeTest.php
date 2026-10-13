<?php

use App\Services\Installation;
use App\Services\TokenStore;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

const LATEST_RELEASE = 'https://api.github.com/repos/16bitsrl/fattureincloud-cli/releases/latest';

beforeEach(function () {
    /** @var TestCase $this */
    $configDir = sys_get_temp_dir().'/fic-tests-'.bin2hex(random_bytes(6));
    mkdir($configDir, 0700, true);
    TokenStore::setConfigDir($configDir);

    config(['app.version' => '1.3.0']);
    $this->app->instance(Installation::class, new Installation('/tmp/fic', 'fic-macos-aarch64'));
    putenv('FIC_NO_UPDATE_CHECK');
    putenv('CI');
});

it('tells when a newer version is available', function () {
    /** @var TestCase $this */
    Http::fake([LATEST_RELEASE => Http::response(['tag_name' => 'v2.0.0'])]);

    $this->artisan('skill', ['--list' => true])
        ->expectsOutputToContain('fic 2.0.0 is available')
        ->assertSuccessful();
});

it('checks for a new version at most once a day', function () {
    /** @var TestCase $this */
    Http::fake([LATEST_RELEASE => Http::response(['tag_name' => 'v2.0.0'])]);

    $this->artisan('skill', ['--list' => true])->assertSuccessful();
    $this->artisan('skill', ['--list' => true])
        ->expectsOutputToContain('fic 2.0.0 is available')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('stays quiet when up to date', function () {
    /** @var TestCase $this */
    Http::fake([LATEST_RELEASE => Http::response(['tag_name' => 'v1.3.0'])]);

    $this->artisan('skill', ['--list' => true])
        ->doesntExpectOutputToContain('is available')
        ->assertSuccessful();
});

it('does not check when disabled', function () {
    /** @var TestCase $this */
    putenv('FIC_NO_UPDATE_CHECK=1');
    Http::fake();

    $this->artisan('skill', ['--list' => true])->assertSuccessful();

    Http::assertNothingSent();
});

it('does not check when running from source', function () {
    /** @var TestCase $this */
    $this->app->instance(Installation::class, new Installation(null, null, Installation::SOURCE));
    Http::fake();

    $this->artisan('skill', ['--list' => true])->assertSuccessful();

    Http::assertNothingSent();
});
