<?php

use App\Services\FatturaElettronica\XmlInvoiceParser;

it('parses fiscal edge cases from real-world sample fixtures', function () {
    $parser = app(XmlInvoiceParser::class);

    $discountInvoice = $parser->parseFile(base_path('tests/Fixtures/einvoice/IT03297040366_00005.xml'));
    $reducedVatInvoice = $parser->parseFile(base_path('tests/Fixtures/einvoice/IT06363391001_00010.xml'));
    $natureInvoice = $parser->parseFile(base_path('tests/Fixtures/einvoice/IT06363391001_00018.xml'));

    expect($discountInvoice['items'][0]['discounts'][0]['type'])->toBe('SC')
        ->and($discountInvoice['items'][0]['discounts'][0]['percentage'])->toBe(10.0)
        ->and($discountInvoice['tax_summary'][0]['taxable_amount'])->toBe(9.0)
        ->and($discountInvoice['tax_summary'][0]['tax_amount'])->toBe(1.98)
        ->and($reducedVatInvoice['items'][0]['vat_rate'])->toBe(10.0)
        ->and($reducedVatInvoice['tax_summary'][0]['vat_rate'])->toBe(10.0)
        ->and($natureInvoice['items'][0]['vat_rate'])->toBe(0.0)
        ->and($natureInvoice['items'][0]['vat_nature'])->toBe('N3.3')
        ->and($natureInvoice['tax_summary'][0]['vat_nature'])->toBe('N3.3');
});

it('parses procurement references, attachments, and fiscal blocks', function () {
    $parser = app(XmlInvoiceParser::class);

    $invoice = $parser->parseFile(base_path('tests/Fixtures/einvoice/advanced-features.xml'));

    expect($invoice['causals'])->toHaveCount(2)
        ->and($invoice['references']['orders'][0]['id_document'])->toBe('PO123')
        ->and($invoice['references']['orders'][0]['cup'])->toBe('CUP456')
        ->and($invoice['references']['orders'][0]['cig'])->toBe('CIG123')
        ->and($invoice['references']['contracts'][0]['id_document'])->toBe('CTR789')
        ->and($invoice['withholding_taxes'][0]['type'])->toBe('RT01')
        ->and($invoice['social_security'][0]['type'])->toBe('TC22')
        ->and($invoice['stamp_duty'])->toBe(2.0)
        ->and($invoice['attachments'][0]['name'])->toBe('memo.txt')
        ->and(base64_decode($invoice['attachments'][0]['content_base64'], true))->toBe('Hello FIC');
});
