<?php

use App\Services\TokenStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

beforeEach(function () {
    $configDir = sys_get_temp_dir().'/fic-tests-'.bin2hex(random_bytes(6));
    mkdir($configDir, 0700, true);

    TokenStore::setConfigDir($configDir);
    TokenStore::save(['access_token' => 'test-token']);
    TokenStore::setCompanyId(123);
});

it('shows a dry-run JSON plan for XML imports', function () {
    /** @var TestCase $this */
    Http::fake([
        'https://api-v2.fattureincloud.it/c/123/settings/vat_types' => Http::response([
            'data' => [
                ['id' => 7, 'value' => 22, 'description' => 'IVA 22%'],
            ],
        ]),
        'https://api-v2.fattureincloud.it/c/123/settings/payment_methods' => Http::response([
            'data' => [
                [
                    'id' => 9,
                    'name' => 'Bonifico',
                    'ei_payment_method' => 'MP05',
                    'is_default' => true,
                    'default_payment_account' => ['id' => 11, 'name' => 'Banca'],
                ],
            ],
        ]),
        'https://api-v2.fattureincloud.it/user/companies*' => Http::response([
            'data' => [
                'companies' => [
                    [
                        'id' => 123,
                        'name' => '16bit S.r.l.',
                        'vat_number' => 'IT01234567890',
                        'tax_code' => '01234567890',
                    ],
                ],
            ],
        ]),
        'https://api-v2.fattureincloud.it/c/123/entities/clients*' => Http::response(['data' => []]),
    ]);

    $this->artisan('einvoice:import', [
        'path' => base_path('tests/Fixtures/einvoice/basic-v2025.xml'),
        '--company-id' => 123,
        '--dry-run' => true,
        '--json' => true,
    ])->assertExitCode(0);

    Http::assertSentCount(6);
});

it('imports with --json --yes and outputs plan plus results', function () {
    /** @var TestCase $this */
    Http::fake([
        'https://api-v2.fattureincloud.it/c/123/settings/vat_types' => Http::response([
            'data' => [
                ['id' => 7, 'value' => 22, 'description' => 'IVA 22%'],
            ],
        ]),
        'https://api-v2.fattureincloud.it/c/123/settings/payment_methods' => Http::response([
            'data' => [
                [
                    'id' => 9,
                    'name' => 'Bonifico',
                    'ei_payment_method' => 'MP05',
                    'is_default' => true,
                    'default_payment_account' => ['id' => 11, 'name' => 'Banca'],
                ],
            ],
        ]),
        'https://api-v2.fattureincloud.it/user/companies*' => Http::response([
            'data' => [
                'companies' => [
                    [
                        'id' => 123,
                        'name' => '16bit S.r.l.',
                        'vat_number' => 'IT01234567890',
                        'tax_code' => '01234567890',
                    ],
                ],
            ],
        ]),
        'https://api-v2.fattureincloud.it/c/123/entities/clients*' => Http::response(['data' => []]),
        'https://api-v2.fattureincloud.it/c/123/issued_documents' => Http::response(['data' => ['id' => 777]]),
    ]);

    $exitCode = Artisan::call('einvoice:import', [
        'path' => base_path('tests/Fixtures/einvoice/basic-v2025.xml'),
        '--company-id' => 123,
        '--yes' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($payload['dry_run'])->toBeFalse()
        ->and($payload['results'])->toHaveCount(1)
        ->and($payload['results'][0]['status'])->toBe('imported')
        ->and($payload['results'][0]['id'])->toBe(777);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/c/123/issued_documents'));
});

it('refuses to import with --json without --yes', function () {
    /** @var TestCase $this */
    Http::fake([
        'https://api-v2.fattureincloud.it/c/123/settings/vat_types' => Http::response(['data' => []]),
        'https://api-v2.fattureincloud.it/c/123/settings/payment_methods' => Http::response(['data' => []]),
        'https://api-v2.fattureincloud.it/user/companies*' => Http::response([
            'data' => ['companies' => [
                ['id' => 123, 'name' => '16bit S.r.l.', 'vat_number' => 'IT01234567890', 'tax_code' => '01234567890'],
            ]],
        ]),
        'https://api-v2.fattureincloud.it/c/123/entities/clients*' => Http::response(['data' => []]),
    ]);

    $this->artisan('einvoice:import', [
        'path' => base_path('tests/Fixtures/einvoice/basic-v2025.xml'),
        '--company-id' => 123,
        '--json' => true,
    ])
        ->expectsOutputToContain('Importing with --json requires --yes')
        ->assertExitCode(1);

    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

it('rejects xml files that do not belong to the selected company', function () {
    /** @var TestCase $this */
    Http::fake([
        'https://api-v2.fattureincloud.it/c/123/settings/vat_types' => Http::response([
            'data' => [
                ['id' => 7, 'value' => 22, 'description' => 'IVA 22%'],
            ],
        ]),
        'https://api-v2.fattureincloud.it/c/123/settings/payment_methods' => Http::response([
            'data' => [
                [
                    'id' => 9,
                    'name' => 'Bonifico',
                    'ei_payment_method' => 'MP05',
                    'is_default' => true,
                    'default_payment_account' => ['id' => 11, 'name' => 'Banca'],
                ],
            ],
        ]),
        'https://api-v2.fattureincloud.it/user/companies*' => Http::response([
            'data' => [
                'companies' => [
                    [
                        'id' => 123,
                        'name' => '16bit S.r.l.',
                        'vat_number' => 'IT01234567890',
                        'tax_code' => '01234567890',
                    ],
                ],
            ],
        ]),
        'https://api-v2.fattureincloud.it/c/123/entities/clients*' => Http::response(['data' => []]),
    ]);

    $this->artisan('einvoice:import', [
        'path' => base_path('tests/Fixtures/einvoice/CHE114993395IVA_00007.xml'),
        '--company-id' => 123,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('XML import recap')
        ->expectsOutputToContain('Status:     BLOCKED')
        ->expectsOutputToContain('This XML does not belong to the selected company')
        ->assertExitCode(1);
});
