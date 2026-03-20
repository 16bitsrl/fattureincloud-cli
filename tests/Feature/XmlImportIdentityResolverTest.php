<?php

use App\Services\FatturaElettronica\XmlImportIdentityResolver;
use App\Services\FatturaElettronica\XmlInvoiceMapper;
use App\Services\FatturaElettronica\XmlInvoiceParser;
use App\Services\FicApiClient;
use Illuminate\Support\Facades\Http;

it('recognizes when neither XML party is the selected company', function () {
    Http::fake([
        'https://api-v2.fattureincloud.it/user/companies*' => Http::response([
            'data' => [
                'companies' => [
                    [
                        'id' => 555,
                        'name' => 'Another Company S.r.l.',
                        'vat_number' => 'IT99999999999',
                        'tax_code' => '99999999999',
                    ],
                ],
            ],
        ]),
        'https://api-v2.fattureincloud.it/c/555/entities/clients*' => Http::response(['data' => []]),
    ]);

    $parser = app(XmlInvoiceParser::class);
    $resolver = app(XmlImportIdentityResolver::class);
    $api = new FicApiClient('test-token');
    $invoice = $parser->parseFile(base_path('tests/Fixtures/einvoice/CHE114993395IVA_00007.xml'));

    $identity = $resolver->resolve($api, 555, 'issued', $invoice);

    expect($identity['seller_company_match']['matched'])->toBeFalse()
        ->and($identity['buyer_company_match']['matched'])->toBeFalse()
        ->and($identity['entity_match'])->toBeNull()
        ->and($identity['warnings'][0])->toContain('Neither CedentePrestatore nor CessionarioCommittente matches');
});

it('reuses an existing client when the XML counterparty matches one in the database', function () {
    Http::fake([
        'https://api-v2.fattureincloud.it/user/companies*' => Http::response([
            'data' => [
                'companies' => [
                    [
                        'id' => 123,
                        'name' => 'YourCompany S.r.l.',
                        'vat_number' => 'IT06363391001',
                        'tax_code' => '06363391001',
                    ],
                ],
            ],
        ]),
        'https://api-v2.fattureincloud.it/c/123/entities/clients*' => Http::response([
            'data' => [
                [
                    'id' => 321,
                    'name' => 'Public Administration',
                    'vat_number' => 'IT06363391001',
                    'tax_code' => '06363391001',
                ],
            ],
        ]),
    ]);

    $parser = app(XmlInvoiceParser::class);
    $resolver = app(XmlImportIdentityResolver::class);
    $mapper = app(XmlInvoiceMapper::class);
    $api = new FicApiClient('test-token');
    $invoice = $parser->parseFile(base_path('tests/Fixtures/einvoice/IT06363391001_00001.xml'));
    $identity = $resolver->resolve($api, 123, 'issued', $invoice);
    $mapped = $mapper->map($invoice, 'issued', [
        ['id' => 7, 'value' => 22, 'description' => 'IVA 22%'],
    ], [
        [
            'id' => 9,
            'name' => 'Bonifico',
            'ei_payment_method' => 'MP05',
            'is_default' => true,
            'default_payment_account' => ['id' => 11, 'name' => 'Banca'],
        ],
    ], $identity);

    expect($identity['seller_company_match']['matched'])->toBeTrue()
        ->and($identity['entity_match']['id'])->toBe(321)
        ->and($mapped['payload']['data']['entity'])->toBe(['id' => 321])
        ->and($mapped['summary']['seller_is_company'])->toBeTrue()
        ->and($mapped['summary']['matched_entity_id'])->toBe(321);
});
