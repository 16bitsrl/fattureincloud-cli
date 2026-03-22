<?php

namespace App\Services\FatturaElettronica;

use Illuminate\Support\Str;
use RuntimeException;

class XmlInvoiceMapper
{
    /**
     * @param  array<int, array<string, mixed>>  $vatTypes
     * @param  array<int, array<string, mixed>>  $paymentMethods
     * @return array{payload: array<string, mixed>, summary: array<string, mixed>, warnings: array<int, string>}
     */
    public function map(array $invoice, string $direction, array $vatTypes = [], array $paymentMethods = [], array $identity = []): array
    {
        $warnings = $invoice['warnings'] ?? [];
        $apiType = $this->mapDocumentType($invoice['document_type_code'] ?? '', $direction);
        $entity = $direction === 'issued' ? ($invoice['buyer'] ?? []) : ($invoice['seller'] ?? []);
        $isElectronicXml = in_array($invoice['transmission_format'] ?? null, ['FPR12', 'FPA12'], true);
        $items = [];
        $recognizedVatRows = 0;

        foreach ($invoice['items'] ?? [] as $item) {
            $mappedVat = $this->matchVatType((float) ($item['vat_rate'] ?? 0.0), (string) ($item['vat_nature'] ?? ''), $vatTypes);

            if (isset($mappedVat['id'])) {
                $recognizedVatRows++;
            }

            if (($item['vat_nature'] ?? '') !== '' && ! isset($mappedVat['id'])) {
                $warnings[] = 'A non-taxable VAT row was imported without an exact Fatture in Cloud VAT type match.';
            }

            $items[] = array_filter([
                'code' => $item['code'] ?? null,
                'name' => $item['name'] ?? null,
                'description' => $item['description'] ?? null,
                'qty' => $item['qty'] ?? null,
                'measure' => $item['measure'] ?? null,
                'net_price' => $item['net_price'] ?? null,
                'vat' => $mappedVat,
                'ei_raw' => $item['raw'] ?? null,
            ], fn ($value) => $value !== null && $value !== '' && $value !== []);
        }

        if ($items === []) {
            throw new RuntimeException('The XML does not contain any invoice lines.');
        }

        $invoiceDate = $invoice['document_date'] ?? null;
        $payments = $this->mapPayments($invoice['payments'] ?? [], $paymentMethods, $invoiceDate);
        $number = $direction === 'issued'
            ? $this->splitIssuedNumber((string) ($invoice['document_number'] ?? ''))
            : null;
        $taxableBase = $this->taxableBase($invoice);
        $withholding = $this->mapWithholdingTaxes($invoice, $taxableBase);
        $socialSecurity = $this->mapSocialSecurity($invoice, $taxableBase);
        $electronicData = $direction === 'issued' && $isElectronicXml
            ? $this->mapIssuedEInvoiceData($invoice, $payments, $paymentMethods)
            : null;

        if ($direction === 'issued' && $number['number'] === null) {
            $warnings[] = 'The invoice number could not be split into number and numeration; Fatture in Cloud will auto-assign the numeric part.';
        }

        $document = array_filter([
            'type' => $apiType,
            'entity' => $this->mapEntity($entity, $invoice, $direction, $identity),
            'date' => $invoice['document_date'] ?? null,
            'currency' => [
                'id' => $invoice['currency'] ?? 'EUR',
            ],
            'subject' => $this->subjectFromInvoice($invoice),
            'notes' => $this->notesFromInvoice($invoice),
            'items_list' => $items,
            'payments_list' => $payments,
            'ei_raw' => $invoice['raw'] ?? null,
            'stamp_duty' => $invoice['stamp_duty'] ?? null,
            'e_invoice' => $direction === 'issued' ? $isElectronicXml : null,
            'ei_data' => $electronicData,
            'withholding_tax' => $direction === 'issued' ? ($withholding['withholding_tax'] ?? null) : null,
            'withholding_tax_taxable' => $direction === 'issued' ? ($withholding['withholding_tax_taxable'] ?? null) : null,
            'other_withholding_tax' => $direction === 'issued' ? ($withholding['other_withholding_tax'] ?? null) : null,
            'ei_withholding_tax_causal' => $direction === 'issued' ? ($withholding['ei_withholding_tax_causal'] ?? null) : null,
            'ei_other_withholding_tax_type' => $direction === 'issued' ? ($withholding['ei_other_withholding_tax_type'] ?? null) : null,
            'ei_other_withholding_tax_causal' => $direction === 'issued' ? ($withholding['ei_other_withholding_tax_causal'] ?? null) : null,
            'amount_withholding_tax' => $direction === 'received' ? ($withholding['amount_withholding_tax'] ?? null) : null,
            'amount_other_withholding_tax' => $direction === 'received' ? ($withholding['amount_other_withholding_tax'] ?? null) : null,
            'cassa' => $direction === 'issued' ? ($socialSecurity['cassa'] ?? null) : null,
            'cassa_taxable' => $direction === 'issued' ? ($socialSecurity['cassa_taxable'] ?? null) : null,
            'ei_cassa_type' => $direction === 'issued' ? ($socialSecurity['ei_cassa_type'] ?? null) : null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        if ($direction === 'issued') {
            $document['number'] = $number['number'];

            if ($number['numeration'] !== null) {
                $document['numeration'] = $number['numeration'];
            }
        } else {
            $document['invoice_number'] = $invoice['document_number'] ?? null;
        }

        return [
            'payload' => ['data' => array_filter($document, fn ($value) => $value !== null)],
            'summary' => [
                'file' => $invoice['file_name'] ?? '',
                'direction' => $direction,
                'api_type' => $apiType,
                'document_type_code' => $invoice['document_type_code'] ?? null,
                'number' => $invoice['document_number'] ?? null,
                'date' => $invoice['document_date'] ?? null,
                'counterparty' => $entity['name'] ?? '',
                'total' => $invoice['total_amount'] ?? null,
                'currency' => $invoice['currency'] ?? 'EUR',
                'seller_name' => $invoice['seller']['name'] ?? null,
                'seller_vat' => $invoice['seller']['vat_number'] ?? null,
                'seller_tax_code' => $invoice['seller']['tax_code'] ?? null,
                'buyer_name' => $invoice['buyer']['name'] ?? null,
                'buyer_vat' => $invoice['buyer']['vat_number'] ?? null,
                'buyer_tax_code' => $invoice['buyer']['tax_code'] ?? null,
                'electronic' => $isElectronicXml,
                'seller_is_company' => (bool) ($identity['seller_company_match']['matched'] ?? false),
                'buyer_is_company' => (bool) ($identity['buyer_company_match']['matched'] ?? false),
                'expected_direction' => $identity['expected_direction'] ?? null,
                'matched_entity_id' => $identity['entity_match']['id'] ?? null,
                'matched_entity_type' => isset($identity['entity_match']) ? ($direction === 'issued' ? 'client' : 'supplier') : null,
                'recognized_vat_rows' => $recognizedVatRows,
                'total_vat_rows' => count($invoice['items'] ?? []),
                'recognized_withholding_taxes' => count($invoice['withholding_taxes'] ?? []),
                'recognized_social_security_blocks' => count($invoice['social_security'] ?? []),
                'recognized_attachments' => count($invoice['attachments'] ?? []),
                'recognized_references' => $this->countReferences($invoice),
                'recognition_status' => $this->recognitionStatus($identity, $recognizedVatRows, count($invoice['items'] ?? [])),
            ],
            'warnings' => array_values(array_unique(array_filter(array_merge($warnings, $identity['warnings'] ?? [])))),
        ];
    }

    protected function mapDocumentType(string $documentTypeCode, string $direction): string
    {
        return match ($direction) {
            'issued' => match ($documentTypeCode) {
                'TD04', 'TD08' => 'credit_note',
                'TD16', 'TD17', 'TD18', 'TD19', 'TD20', 'TD21', 'TD22', 'TD23', 'TD28', 'TD29' => 'self_supplier_invoice',
                'TD01', 'TD02', 'TD03', 'TD06', 'TD24', 'TD25', 'TD27' => 'invoice',
                default => throw new RuntimeException("Unsupported TipoDocumento for issued import: {$documentTypeCode}"),
            },
            'received' => match ($documentTypeCode) {
                'TD04', 'TD08' => 'passive_credit_note',
                'TD16', 'TD17', 'TD18', 'TD19', 'TD20', 'TD21', 'TD22', 'TD23', 'TD28', 'TD29' => 'self_invoice',
                'TD01', 'TD02', 'TD03', 'TD06', 'TD24', 'TD25', 'TD27' => 'expense',
                default => throw new RuntimeException("Unsupported TipoDocumento for received import: {$documentTypeCode}"),
            },
            default => throw new RuntimeException("Unsupported import direction: {$direction}"),
        };
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return array<string, mixed>
     */
    protected function mapEntity(array $entity, array $invoice, string $direction, array $identity = []): array
    {
        if (isset($identity['entity_match']['id'])) {
            return ['id' => $identity['entity_match']['id']];
        }

        $destinationCode = trim((string) ($invoice['destination_code'] ?? ''));
        $certifiedEmail = trim((string) ($invoice['recipient_certified_email'] ?? ''));

        return array_filter([
            'name' => $entity['name'] ?? null,
            'type' => $entity['type'] ?? null,
            'first_name' => $entity['first_name'] ?? null,
            'last_name' => $entity['last_name'] ?? null,
            'vat_number' => $entity['vat_number'] ?? null,
            'tax_code' => $entity['tax_code'] ?? null,
            'address_street' => $entity['address_street'] ?? null,
            'address_postal_code' => $entity['address_postal_code'] ?? null,
            'address_city' => $entity['address_city'] ?? null,
            'address_province' => $entity['address_province'] ?? null,
            'country_iso' => $entity['country_iso'] ?? null,
            'country' => ($entity['country_iso'] ?? null) === 'IT' ? 'Italia' : ($entity['country_iso'] ?? null),
            'email' => $entity['email'] ?? null,
            'phone' => $entity['phone'] ?? null,
            'certified_email' => $direction === 'issued' ? ($certifiedEmail ?: null) : ($entity['certified_email'] ?? null),
            'e_invoice' => $direction === 'issued' ? $this->shouldEnableEntityEInvoice($entity, $invoice) : null,
            'ei_code' => $direction === 'issued' ? $this->normalizeDestinationCode($destinationCode) : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     * @param  array<int, array<string, mixed>>  $paymentMethods
     * @return array<string, mixed>
     */
    protected function mapIssuedEInvoiceData(array $invoice, array $payments, array $paymentMethods): array
    {
        $firstPayment = $invoice['payments'][0] ?? [];
        $mappedPaymentMethod = $this->matchPaymentMethod((string) ($firstPayment['method_code'] ?? ''), $paymentMethods);
        $reference = $this->primaryReference($invoice);

        return array_filter([
            'original_document_type' => $reference['type'] ?? null,
            'od_number' => $reference['id_document'] ?? null,
            'od_date' => $reference['date'] ?? null,
            'cig' => $reference['cig'] ?? null,
            'cup' => $reference['cup'] ?? null,
            'payment_method' => $firstPayment['method_code']
                ?? $mappedPaymentMethod['ei_payment_method']
                ?? null,
            'bank_name' => $firstPayment['bank_name'] ?? $mappedPaymentMethod['bank_name'] ?? null,
            'bank_iban' => $firstPayment['iban'] ?? $mappedPaymentMethod['bank_iban'] ?? null,
            'bank_beneficiary' => $mappedPaymentMethod['bank_beneficiary'] ?? null,
            'invoice_number' => $invoice['document_number'] ?? null,
            'invoice_date' => $invoice['document_date'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapWithholdingTaxes(array $invoice, ?float $taxableBase): array
    {
        $result = [];

        foreach ($invoice['withholding_taxes'] ?? [] as $withholding) {
            $rate = isset($withholding['rate']) ? (float) $withholding['rate'] : null;
            $amount = isset($withholding['amount']) ? (float) $withholding['amount'] : null;
            $taxablePercent = ($taxableBase !== null && $taxableBase > 0 && $rate !== null && $rate > 0 && $amount !== null)
                ? round(($amount / ($taxableBase * ($rate / 100))) * 100, 2)
                : null;

            if (($withholding['type'] ?? '') === 'RT01') {
                $result['withholding_tax'] = $rate;
                $result['withholding_tax_taxable'] = $taxablePercent;
                $result['ei_withholding_tax_causal'] = $withholding['causal'] ?? null;
                $result['amount_withholding_tax'] = $amount;

                continue;
            }

            $result['other_withholding_tax'] = $rate;
            $result['ei_other_withholding_tax_type'] = $withholding['type'] ?? null;
            $result['ei_other_withholding_tax_causal'] = $withholding['causal'] ?? null;
            $result['amount_other_withholding_tax'] = $amount;
        }

        return array_filter($result, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapSocialSecurity(array $invoice, ?float $taxableBase): array
    {
        $firstContribution = $invoice['social_security'][0] ?? null;

        if (! is_array($firstContribution)) {
            return [];
        }

        $amount = isset($firstContribution['amount']) ? (float) $firstContribution['amount'] : null;
        $taxablePercent = ($taxableBase !== null && $taxableBase > 0 && $amount !== null)
            ? round(($amount / $taxableBase) * 100, 2)
            : null;

        return array_filter([
            'cassa' => $firstContribution['rate'] ?? null,
            'cassa_taxable' => $taxablePercent,
            'ei_cassa_type' => $firstContribution['type'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     * @param  array<int, array<string, mixed>>  $paymentMethods
     * @return array<int, array<string, mixed>>
     */
    protected function mapPayments(array $payments, array $paymentMethods, ?string $invoiceDate = null): array
    {
        $mapped = [];

        foreach ($payments as $payment) {
            $paymentMethod = $this->matchPaymentMethod((string) ($payment['method_code'] ?? ''), $paymentMethods);

            $dueDate = ! empty($payment['due_date']) ? $payment['due_date'] : $invoiceDate;

            $mapped[] = array_filter([
                'due_date' => $dueDate,
                'amount' => $payment['amount'] ?? null,
                'payment_account' => $paymentMethod['default_payment_account'] ?? null,
                'payment_terms' => isset($payment['due_date']) ? ['type' => 'standard'] : null,
                'status' => 'not_paid',
                'ei_raw' => $payment['raw'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $mapped;
    }

    /**
     * @param  array<int, array<string, mixed>>  $paymentMethods
     * @return array<string, mixed>
     */
    protected function matchPaymentMethod(string $code, array $paymentMethods): array
    {
        foreach ($paymentMethods as $paymentMethod) {
            if (($paymentMethod['ei_payment_method'] ?? null) === $code) {
                return $paymentMethod;
            }
        }

        foreach ($paymentMethods as $paymentMethod) {
            if (($paymentMethod['is_default'] ?? false) === true) {
                return $paymentMethod;
            }
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $vatTypes
     * @return array<string, mixed>
     */
    protected function matchVatType(float $rate, string $nature, array $vatTypes): array
    {
        foreach ($vatTypes as $vatType) {
            if ((float) ($vatType['value'] ?? -1) === $rate && $nature === '') {
                return $this->mapVatType($vatType);
            }
        }

        $normalizedNature = $this->normalizeNature($nature);

        foreach ($vatTypes as $vatType) {
            if ((float) ($vatType['value'] ?? -1) !== $rate) {
                continue;
            }

            if ($normalizedNature !== '' && $this->normalizeNature((string) ($vatType['ei_type'] ?? '')) === $normalizedNature) {
                return $this->mapVatType($vatType);
            }
        }

        return array_filter([
            'value' => $rate,
            'ei_type' => $nature ?: null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $vatType
     * @return array<string, mixed>
     */
    protected function mapVatType(array $vatType): array
    {
        return array_filter([
            'id' => $vatType['id'] ?? null,
            'value' => $vatType['value'] ?? null,
            'description' => $vatType['description'] ?? null,
            'notes' => $vatType['notes'] ?? null,
            'ei_type' => $vatType['ei_type'] ?? null,
            'ei_description' => $vatType['ei_description'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array{number: ?int, numeration: ?string}
     */
    protected function splitIssuedNumber(string $number): array
    {
        $number = trim($number);

        if ($number === '') {
            return ['number' => null, 'numeration' => null];
        }

        if (preg_match('/^(?<prefix>[^\d]*)(?<number>\d+)(?<suffix>.*)$/', $number, $matches) !== 1) {
            return ['number' => null, 'numeration' => $number];
        }

        $numeration = trim(($matches['prefix'] ?? '').($matches['suffix'] ?? ''));

        return [
            'number' => (int) $matches['number'],
            'numeration' => $numeration !== '' ? $numeration : null,
        ];
    }

    protected function normalizeNature(string $nature): string
    {
        return Str::upper(Str::replace(['.', ' '], '', ltrim($nature, 'Nn')));
    }

    protected function shouldEnableEntityEInvoice(array $entity, array $invoice): bool
    {
        if (($invoice['recipient_certified_email'] ?? '') !== '') {
            return true;
        }

        return $this->normalizeDestinationCode((string) ($invoice['destination_code'] ?? '')) !== null;
    }

    protected function normalizeDestinationCode(string $code): ?string
    {
        $code = trim($code);

        if ($code === '' || $code === '0000000') {
            return null;
        }

        return $code;
    }

    protected function taxableBase(array $invoice): ?float
    {
        $sum = 0.0;

        foreach ($invoice['tax_summary'] ?? [] as $summary) {
            $sum += (float) ($summary['taxable_amount'] ?? 0);
        }

        return $sum > 0 ? $sum : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function primaryReference(array $invoice): array
    {
        $mapping = [
            'orders' => 'ordine',
            'contracts' => 'contratto',
            'conventions' => 'convenzione',
        ];

        foreach ($mapping as $key => $type) {
            $reference = $invoice['references'][$key][0] ?? null;

            if (is_array($reference)) {
                $reference['type'] = $type;

                return $reference;
            }
        }

        return [];
    }

    protected function subjectFromInvoice(array $invoice): ?string
    {
        return $invoice['causals'][0] ?? null;
    }

    protected function notesFromInvoice(array $invoice): ?string
    {
        $causals = array_values(array_filter($invoice['causals'] ?? []));

        return $causals === [] ? null : implode("\n", $causals);
    }

    protected function countReferences(array $invoice): int
    {
        $count = 0;

        foreach (($invoice['references'] ?? []) as $group) {
            $count += is_array($group) ? count($group) : 0;
        }

        return $count;
    }

    protected function recognitionStatus(array $identity, int $recognizedVatRows, int $totalVatRows): string
    {
        $companyRecognized = ($identity['seller_company_match']['matched'] ?? false) || ($identity['buyer_company_match']['matched'] ?? false);
        $entityRecognized = isset($identity['entity_match']['id']);
        $vatRecognized = $totalVatRows === 0 || $recognizedVatRows === $totalVatRows;

        if ($companyRecognized && $entityRecognized && $vatRecognized) {
            return 'complete';
        }

        if ($companyRecognized || $entityRecognized || $vatRecognized) {
            return 'partial';
        }

        return 'minimal';
    }
}
