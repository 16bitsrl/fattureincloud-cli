<?php

namespace App\Services\FatturaElettronica;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

class XmlInvoiceParser
{
    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("XML file not found: {$path}");
        }

        $xmlPath = $this->prepareReadableXml($path);

        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;

        if (! @$document->load($xmlPath)) {
            throw new RuntimeException("Unable to parse XML file: {$path}");
        }

        $root = $document->documentElement;

        if (! $root instanceof DOMElement) {
            throw new RuntimeException('Invalid XML: missing document element.');
        }

        $namespace = $root->namespaceURI;

        if (! is_string($namespace) || $namespace === '') {
            throw new RuntimeException('Unsupported XML: missing FatturaPA namespace.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fe', $namespace);

        $header = $this->firstElement($xpath, '/fe:FatturaElettronica/fe:FatturaElettronicaHeader');
        $body = $this->firstElement($xpath, '/fe:FatturaElettronica/fe:FatturaElettronicaBody');

        if (! $header || ! $body) {
            throw new RuntimeException('Unsupported XML: missing invoice header or body.');
        }

        $warnings = [];

        if (count($this->elements($xpath, '/fe:FatturaElettronica/fe:FatturaElettronicaBody')) > 1) {
            $warnings[] = 'Only the first FatturaElettronicaBody section is imported.';
        }

        return [
            'file_name' => basename($path),
            'path' => $path,
            'source_format' => str_ends_with(strtolower($path), '.p7m') ? 'p7m' : 'xml',
            'version' => $root->getAttribute('versione'),
            'transmission_format' => $this->string($xpath, 'string(fe:DatiTrasmissione/fe:FormatoTrasmissione)', $header),
            'destination_code' => $this->string($xpath, 'string(fe:DatiTrasmissione/fe:CodiceDestinatario)', $header),
            'recipient_certified_email' => $this->string($xpath, 'string(fe:DatiTrasmissione/fe:PECDestinatario)', $header),
            'document_type_code' => $this->string($xpath, 'string(fe:DatiGenerali/fe:DatiGeneraliDocumento/fe:TipoDocumento)', $body),
            'document_number' => $this->string($xpath, 'string(fe:DatiGenerali/fe:DatiGeneraliDocumento/fe:Numero)', $body),
            'document_date' => $this->string($xpath, 'string(fe:DatiGenerali/fe:DatiGeneraliDocumento/fe:Data)', $body),
            'currency' => $this->string($xpath, 'string(fe:DatiGenerali/fe:DatiGeneraliDocumento/fe:Divisa)', $body) ?: 'EUR',
            'causals' => $this->strings($xpath, 'fe:DatiGenerali/fe:DatiGeneraliDocumento/fe:Causale', $body),
            'total_amount' => $this->decimal($xpath, 'string(fe:DatiGenerali/fe:DatiGeneraliDocumento/fe:ImportoTotaleDocumento)', $body),
            'stamp_duty' => $this->decimal($xpath, 'string(fe:DatiGenerali/fe:DatiGeneraliDocumento/fe:DatiBollo/fe:ImportoBollo)', $body),
            'seller' => $this->parseParty($xpath, 'fe:CedentePrestatore', $header),
            'buyer' => $this->parseParty($xpath, 'fe:CessionarioCommittente', $header),
            'payments' => $this->parsePayments($xpath, $body),
            'items' => $this->parseItems($xpath, $body),
            'references' => $this->parseReferences($xpath, $body),
            'attachments' => $this->parseAttachments($xpath, $body),
            'tax_summary' => $this->parseTaxSummary($xpath, $body),
            'withholding_taxes' => $this->parseWithholdingTaxes($xpath, $body),
            'social_security' => $this->parseSocialSecurity($xpath, $body),
            'raw' => [
                'FatturaElettronicaHeader' => $this->elementToArray($header),
                'FatturaElettronicaBody' => $this->elementToArray($body),
            ],
            'warnings' => array_values(array_filter($warnings)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseParty(DOMXPath $xpath, string $expression, DOMElement $context): array
    {
        $party = $this->firstElement($xpath, $expression, $context);

        if (! $party) {
            return [];
        }

        $name = $this->string($xpath, 'string(fe:DatiAnagrafici/fe:Anagrafica/fe:Denominazione)', $party);
        $firstName = $this->string($xpath, 'string(fe:DatiAnagrafici/fe:Anagrafica/fe:Nome)', $party);
        $lastName = $this->string($xpath, 'string(fe:DatiAnagrafici/fe:Anagrafica/fe:Cognome)', $party);

        if ($name === '') {
            $name = trim($firstName.' '.$lastName);
        }

        $street = trim(implode(', ', array_filter([
            $this->string($xpath, 'string(fe:Sede/fe:Indirizzo)', $party),
            $this->string($xpath, 'string(fe:Sede/fe:NumeroCivico)', $party),
        ])));

        return [
            'name' => $name,
            'type' => $name !== '' && ($firstName === '' && $lastName === '') ? 'company' : 'person',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'vat_number' => $this->partyVatNumber($xpath, $party),
            'tax_code' => $this->string($xpath, 'string(fe:DatiAnagrafici/fe:CodiceFiscale)', $party),
            'address_street' => $street,
            'address_postal_code' => $this->string($xpath, 'string(fe:Sede/fe:CAP)', $party),
            'address_city' => $this->string($xpath, 'string(fe:Sede/fe:Comune)', $party),
            'address_province' => $this->string($xpath, 'string(fe:Sede/fe:Provincia)', $party),
            'country_iso' => $this->string($xpath, 'string(fe:Sede/fe:Nazione)', $party),
            'email' => $this->string($xpath, 'string(fe:Contatti/fe:Email)', $party),
            'phone' => $this->string($xpath, 'string(fe:Contatti/fe:Telefono)', $party),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseItems(DOMXPath $xpath, DOMElement $body): array
    {
        $items = [];

        foreach ($this->elements($xpath, 'fe:DatiBeniServizi/fe:DettaglioLinee', $body) as $line) {
            if (! $line instanceof DOMElement) {
                continue;
            }

            $qty = $this->decimal($xpath, 'string(fe:Quantita)', $line) ?: 1.0;
            $unitPrice = $this->decimal($xpath, 'string(fe:PrezzoUnitario)', $line);
            $totalPrice = $this->decimal($xpath, 'string(fe:PrezzoTotale)', $line);

            if ($unitPrice === null && $totalPrice !== null && $qty > 0) {
                $unitPrice = round($totalPrice / $qty, 8);
            }

            $items[] = [
                'code' => $this->string($xpath, 'string(fe:CodiceArticolo[1]/fe:CodiceValore)', $line),
                'name' => $this->string($xpath, 'string(fe:Descrizione)', $line),
                'description' => $this->string($xpath, 'string(fe:Descrizione)', $line),
                'qty' => $qty,
                'measure' => $this->string($xpath, 'string(fe:UnitaMisura)', $line),
                'net_price' => $unitPrice,
                'line_total' => $totalPrice,
                'vat_rate' => $this->decimal($xpath, 'string(fe:AliquotaIVA)', $line) ?? 0.0,
                'vat_nature' => $this->string($xpath, 'string(fe:Natura)', $line),
                'discounts' => $this->parseDiscounts($xpath, $line),
                'raw' => $this->elementToArray($line),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parsePayments(DOMXPath $xpath, DOMElement $body): array
    {
        $payments = [];

        foreach ($this->elements($xpath, 'fe:DatiPagamento/fe:DettaglioPagamento', $body) as $payment) {
            if (! $payment instanceof DOMElement) {
                continue;
            }

            $payments[] = [
                'method_code' => $this->string($xpath, 'string(fe:ModalitaPagamento)', $payment),
                'due_date' => $this->string($xpath, 'string(fe:DataScadenzaPagamento)', $payment),
                'amount' => $this->decimal($xpath, 'string(fe:ImportoPagamento)', $payment),
                'iban' => $this->string($xpath, 'string(fe:IBAN)', $payment),
                'raw' => $this->elementToArray($payment),
            ];
        }

        return $payments;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseDiscounts(DOMXPath $xpath, DOMElement $line): array
    {
        $discounts = [];

        foreach ($this->elements($xpath, 'fe:ScontoMaggiorazione', $line) as $discount) {
            $discounts[] = array_filter([
                'type' => $this->string($xpath, 'string(fe:Tipo)', $discount),
                'percentage' => $this->decimal($xpath, 'string(fe:Percentuale)', $discount),
                'amount' => $this->decimal($xpath, 'string(fe:Importo)', $discount),
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $discounts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseTaxSummary(DOMXPath $xpath, DOMElement $body): array
    {
        $summaries = [];

        foreach ($this->elements($xpath, 'fe:DatiBeniServizi/fe:DatiRiepilogo', $body) as $summary) {
            $summaries[] = array_filter([
                'vat_rate' => $this->decimal($xpath, 'string(fe:AliquotaIVA)', $summary),
                'vat_nature' => $this->string($xpath, 'string(fe:Natura)', $summary),
                'taxable_amount' => $this->decimal($xpath, 'string(fe:ImponibileImporto)', $summary),
                'tax_amount' => $this->decimal($xpath, 'string(fe:Imposta)', $summary),
                'reference' => $this->string($xpath, 'string(fe:RiferimentoNormativo)', $summary),
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $summaries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseWithholdingTaxes(DOMXPath $xpath, DOMElement $body): array
    {
        $withholdings = [];

        foreach ($this->elements($xpath, 'fe:DatiGenerali/fe:DatiGeneraliDocumento/fe:DatiRitenuta', $body) as $withholding) {
            $withholdings[] = array_filter([
                'type' => $this->string($xpath, 'string(fe:TipoRitenuta)', $withholding),
                'amount' => $this->decimal($xpath, 'string(fe:ImportoRitenuta)', $withholding),
                'rate' => $this->decimal($xpath, 'string(fe:AliquotaRitenuta)', $withholding),
                'causal' => $this->string($xpath, 'string(fe:CausalePagamento)', $withholding),
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $withholdings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseSocialSecurity(DOMXPath $xpath, DOMElement $body): array
    {
        $contributions = [];

        foreach ($this->elements($xpath, 'fe:DatiGenerali/fe:DatiGeneraliDocumento/fe:DatiCassaPrevidenziale', $body) as $contribution) {
            $contributions[] = array_filter([
                'type' => $this->string($xpath, 'string(fe:TipoCassa)', $contribution),
                'rate' => $this->decimal($xpath, 'string(fe:AlCassa)', $contribution),
                'amount' => $this->decimal($xpath, 'string(fe:ImportoContributoCassa)', $contribution),
                'vat_rate' => $this->decimal($xpath, 'string(fe:AliquotaIVA)', $contribution),
                'vat_nature' => $this->string($xpath, 'string(fe:Natura)', $contribution),
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $contributions;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function parseReferences(DOMXPath $xpath, DOMElement $body): array
    {
        return [
            'orders' => $this->parseReferenceBlocks($xpath, 'fe:DatiGenerali/fe:DatiOrdineAcquisto', $body),
            'contracts' => $this->parseReferenceBlocks($xpath, 'fe:DatiGenerali/fe:DatiContratto', $body),
            'conventions' => $this->parseReferenceBlocks($xpath, 'fe:DatiGenerali/fe:DatiConvenzione', $body),
            'receipts' => $this->parseReferenceBlocks($xpath, 'fe:DatiGenerali/fe:DatiRicezione', $body),
            'related_invoices' => $this->parseReferenceBlocks($xpath, 'fe:DatiGenerali/fe:DatiFattureCollegate', $body),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseReferenceBlocks(DOMXPath $xpath, string $expression, DOMElement $body): array
    {
        $references = [];

        foreach ($this->elements($xpath, $expression, $body) as $reference) {
            $references[] = array_filter([
                'line_numbers' => $this->strings($xpath, 'fe:RiferimentoNumeroLinea', $reference),
                'id_document' => $this->string($xpath, 'string(fe:IdDocumento)', $reference),
                'date' => $this->string($xpath, 'string(fe:Data)', $reference),
                'cup' => $this->string($xpath, 'string(fe:CodiceCUP)', $reference),
                'cig' => $this->string($xpath, 'string(fe:CodiceCIG)', $reference),
                'raw' => $this->elementToArray($reference),
            ], fn ($value) => $value !== null && $value !== '' && $value !== []);
        }

        return $references;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseAttachments(DOMXPath $xpath, DOMElement $body): array
    {
        $attachments = [];

        foreach ($this->elements($xpath, 'fe:Allegati', $body) as $attachment) {
            $attachments[] = array_filter([
                'name' => $this->string($xpath, 'string(fe:NomeAttachment)', $attachment),
                'format' => $this->string($xpath, 'string(fe:FormatoAttachment)', $attachment),
                'description' => $this->string($xpath, 'string(fe:DescrizioneAttachment)', $attachment),
                'content_base64' => $this->string($xpath, 'string(fe:Attachment)', $attachment),
                'algorithm' => $this->string($xpath, 'string(fe:AlgoritmoCompressione)', $attachment),
                'raw' => $this->elementToArray($attachment),
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $attachments;
    }

    protected function partyVatNumber(DOMXPath $xpath, DOMElement $party): string
    {
        $country = $this->string($xpath, 'string(fe:DatiAnagrafici/fe:IdFiscaleIVA/fe:IdPaese)', $party);
        $code = $this->string($xpath, 'string(fe:DatiAnagrafici/fe:IdFiscaleIVA/fe:IdCodice)', $party);

        return trim($country.$code);
    }

    protected function firstElement(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?DOMElement
    {
        $node = $this->elements($xpath, $expression, $context)[0] ?? null;

        return $node instanceof DOMElement ? $node : null;
    }

    protected function string(DOMXPath $xpath, string $expression, ?DOMNode $context = null): string
    {
        return trim((string) $xpath->evaluate($this->normalizeXPath($expression), $context));
    }

    /**
     * @return array<int, string>
     */
    protected function strings(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        return array_values(array_filter(array_map(
            fn (DOMElement $element) => trim($element->textContent),
            $this->elements($xpath, $expression, $context)
        ), fn (string $value) => $value !== ''));
    }

    protected function decimal(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?float
    {
        $value = $this->string($xpath, $expression, $context);

        return $value === '' ? null : (float) str_replace(',', '.', $value);
    }

    /**
     * @return array<string, mixed>|string
     */
    protected function elementToArray(DOMElement $element): array|string
    {
        $children = [];
        $hasElementChildren = false;

        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $hasElementChildren = true;
            $value = $this->elementToArray($child);
            $name = $child->localName;

            if (array_key_exists($name, $children)) {
                if (! is_array($children[$name]) || ! array_is_list($children[$name])) {
                    $children[$name] = [$children[$name]];
                }

                $children[$name][] = $value;
            } else {
                $children[$name] = $value;
            }
        }

        return $hasElementChildren ? $children : trim($element->textContent);
    }

    protected function prepareReadableXml(string $path): string
    {
        if (! str_ends_with(strtolower($path), '.p7m')) {
            return $path;
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'fic-xml-');

        if ($outputPath === false) {
            throw new RuntimeException('Unable to prepare a temporary file for the signed XML.');
        }

        @unlink($outputPath);

        if (openssl_pkcs7_verify($path, PKCS7_NOVERIFY, null, [], null, $outputPath) !== true) {
            $command = sprintf(
                'openssl smime -verify -inform DER -in %s -noverify -out %s 2>/dev/null',
                escapeshellarg($path),
                escapeshellarg($outputPath),
            );

            exec($command, $unusedOutput, $exitCode);

            if ($exitCode !== 0) {
                @unlink($outputPath);

                throw new RuntimeException("Unable to extract XML from signed file: {$path}");
            }
        }

        return $outputPath;
    }

    /**
     * @return array<int, DOMElement>
     */
    protected function elements(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        $query = $context
            ? $xpath->query($this->normalizeXPath($expression), $context)
            : $xpath->query($this->normalizeXPath($expression));

        if ($query === false) {
            return [];
        }

        $elements = [];

        foreach ($query as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    protected function normalizeXPath(string $expression): string
    {
        return preg_replace("/(^|\/|\(|\s)fe:([A-Za-z_][A-Za-z0-9_-]*)/", "$1*[local-name()='$2']", $expression) ?? $expression;
    }
}
