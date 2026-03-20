<?php

use App\Services\FatturaElettronica\XmlInvoiceMapper;
use App\Services\FatturaElettronica\XmlInvoiceParser;

it('marks issued XML imports as electronic invoices by default', function () {
    $parser = app(XmlInvoiceParser::class);
    $mapper = app(XmlInvoiceMapper::class);

    $invoice = $parser->parseFile(base_path('tests/Fixtures/einvoice/basic-v2025.xml'));

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
    ]);

    expect($mapped['payload']['data']['e_invoice'])->toBeTrue()
        ->and($mapped['payload']['data']['ei_data']['payment_method'])->toBe('MP05')
        ->and($mapped['payload']['data']['entity']['e_invoice'])->toBeTrue()
        ->and($mapped['summary']['electronic'])->toBeTrue();
});

it('tracks VAT recognition details for different fiscal samples', function () {
    $parser = app(XmlInvoiceParser::class);
    $mapper = app(XmlInvoiceMapper::class);

    $natureInvoice = $parser->parseFile(base_path('tests/Fixtures/einvoice/IT06363391001_00018.xml'));
    $mappedNature = $mapper->map($natureInvoice, 'issued', [
        ['id' => 21, 'value' => 0, 'description' => 'N3.3', 'ei_type' => 'N3.3'],
    ], [
        ['id' => 9, 'name' => 'Bonifico', 'ei_payment_method' => 'MP05', 'is_default' => true],
    ]);

    $reducedVatInvoice = $parser->parseFile(base_path('tests/Fixtures/einvoice/IT06363391001_00010.xml'));
    $mappedReducedVat = $mapper->map($reducedVatInvoice, 'issued', [
        ['id' => 10, 'value' => 10, 'description' => 'IVA 10%'],
    ], [
        ['id' => 9, 'name' => 'Bonifico', 'ei_payment_method' => 'MP05', 'is_default' => true],
    ]);

    expect($mappedNature['summary']['recognized_vat_rows'])->toBe(2)
        ->and($mappedNature['summary']['total_vat_rows'])->toBe(2)
        ->and($mappedNature['summary']['recognition_status'])->toBe('partial')
        ->and($mappedReducedVat['summary']['recognized_vat_rows'])->toBe(2)
        ->and($mappedReducedVat['summary']['total_vat_rows'])->toBe(2);
});

it('maps structured e-invoice fields before falling back to ei_raw', function () {
    $parser = app(XmlInvoiceParser::class);
    $mapper = app(XmlInvoiceMapper::class);

    $invoice = $parser->parseFile(base_path('tests/Fixtures/einvoice/advanced-features.xml'));
    $mapped = $mapper->map($invoice, 'issued', [
        ['id' => 7, 'value' => 22, 'description' => 'IVA 22%'],
    ], [
        [
            'id' => 9,
            'name' => 'Bonifico',
            'ei_payment_method' => 'MP05',
            'is_default' => true,
            'bank_name' => 'Banca Demo',
            'bank_beneficiary' => '16bit S.r.l.',
            'default_payment_account' => ['id' => 11, 'name' => 'Banca'],
        ],
    ]);

    expect($mapped['payload']['data']['subject'])->toBe('Servizi professionali')
        ->and($mapped['payload']['data']['notes'])->toContain('Commessa procurement')
        ->and($mapped['payload']['data']['ei_data']['original_document_type'])->toBe('ordine')
        ->and($mapped['payload']['data']['ei_data']['od_number'])->toBe('PO123')
        ->and($mapped['payload']['data']['ei_data']['od_date'])->toBe('2026-03-01')
        ->and($mapped['payload']['data']['ei_data']['cup'])->toBe('CUP456')
        ->and($mapped['payload']['data']['ei_data']['cig'])->toBe('CIG123')
        ->and($mapped['payload']['data']['withholding_tax'])->toBe(20.0)
        ->and($mapped['payload']['data']['withholding_tax_taxable'])->toBe(100.0)
        ->and($mapped['payload']['data']['ei_withholding_tax_causal'])->toBe('A')
        ->and($mapped['payload']['data']['cassa'])->toBe(4.0)
        ->and($mapped['payload']['data']['ei_cassa_type'])->toBe('TC22')
        ->and($mapped['payload']['data']['stamp_duty'])->toBe(2.0)
        ->and($mapped['summary']['recognized_references'])->toBe(2)
        ->and($mapped['summary']['recognized_attachments'])->toBe(1);
});
