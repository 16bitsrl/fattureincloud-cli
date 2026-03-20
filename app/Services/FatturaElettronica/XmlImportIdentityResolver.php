<?php

namespace App\Services\FatturaElettronica;

use App\Services\FicApiClient;
use RuntimeException;

class XmlImportIdentityResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(FicApiClient $api, int|string $companyId, string $direction, array $invoice): array
    {
        $company = $this->loadSelectedCompany($api, $companyId);
        $sellerMatch = $this->matchPartyToCompany($invoice['seller'] ?? [], $company);
        $buyerMatch = $this->matchPartyToCompany($invoice['buyer'] ?? [], $company);
        $entityType = $direction === 'issued' ? 'client' : 'supplier';
        $counterparty = $direction === 'issued' ? ($invoice['buyer'] ?? []) : ($invoice['seller'] ?? []);
        $entityMatch = $this->matchExistingEntity($api, $companyId, $entityType, $counterparty);
        $warnings = [];
        $expectedDirection = null;

        if (($sellerMatch['matched'] ?? false) && ! ($buyerMatch['matched'] ?? false)) {
            $expectedDirection = 'issued';
        } elseif (($buyerMatch['matched'] ?? false) && ! ($sellerMatch['matched'] ?? false)) {
            $expectedDirection = 'received';
        }

        if (! ($sellerMatch['matched'] ?? false) && ! ($buyerMatch['matched'] ?? false)) {
            $warnings[] = 'Neither CedentePrestatore nor CessionarioCommittente matches the selected company.';
        } elseif ($expectedDirection !== null && $expectedDirection !== $direction) {
            $warnings[] = "The XML looks like a {$expectedDirection} document for the selected company, but {$direction} was requested.";
        }

        return [
            'company' => $company,
            'seller_company_match' => $sellerMatch,
            'buyer_company_match' => $buyerMatch,
            'expected_direction' => $expectedDirection,
            'entity_match' => $entityMatch,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadSelectedCompany(FicApiClient $api, int|string $companyId): array
    {
        $response = $api->get('/user/companies');

        if ($response->failed()) {
            throw new RuntimeException('Unable to fetch the company list: '.$api->errorMessage($response));
        }

        $companies = [];

        foreach ($response->json('data.companies', []) as $company) {
            if (! is_array($company)) {
                continue;
            }

            $companies[] = $company;

            foreach ($company['controlled_companies'] ?? [] as $controlledCompany) {
                if (is_array($controlledCompany)) {
                    $companies[] = $controlledCompany;
                }
            }
        }

        foreach ($companies as $company) {
            if ((string) ($company['id'] ?? '') === (string) $companyId) {
                return $company;
            }
        }

        return [
            'id' => $companyId,
            'name' => null,
            'vat_number' => null,
            'tax_code' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $party
     * @param  array<string, mixed>  $company
     * @return array<string, mixed>
     */
    protected function matchPartyToCompany(array $party, array $company): array
    {
        $partyVat = $this->normalizeFiscal((string) ($party['vat_number'] ?? ''));
        $partyTaxCode = $this->normalizeFiscal((string) ($party['tax_code'] ?? ''));
        $companyVat = $this->normalizeFiscal((string) ($company['vat_number'] ?? ''));
        $companyTaxCode = $this->normalizeFiscal((string) ($company['tax_code'] ?? ''));

        if ($partyVat !== '' && $companyVat !== '' && $partyVat === $companyVat) {
            return ['matched' => true, 'match_type' => 'vat_number'];
        }

        if ($partyTaxCode !== '' && $companyTaxCode !== '' && $partyTaxCode === $companyTaxCode) {
            return ['matched' => true, 'match_type' => 'tax_code'];
        }

        $similarity = $this->nameSimilarity((string) ($party['name'] ?? ''), (string) ($company['name'] ?? ''));

        if ($similarity >= 92) {
            return ['matched' => true, 'match_type' => 'name', 'similarity' => $similarity];
        }

        return ['matched' => false, 'similarity' => $similarity];
    }

    /**
     * @param  array<string, mixed>  $party
     * @return array<string, mixed>|null
     */
    protected function matchExistingEntity(FicApiClient $api, int|string $companyId, string $entityType, array $party): ?array
    {
        $endpoint = $entityType === 'client' ? 'clients' : 'suppliers';
        $candidates = [];

        foreach ($this->buildQueries($party) as $query) {
            $response = $api->get("/c/{$companyId}/entities/{$endpoint}", [
                'q' => $query,
                'page' => 1,
                'per_page' => 100,
            ]);

            if ($response->failed()) {
                continue;
            }

            foreach ($response->json('data', []) as $candidate) {
                if (! is_array($candidate) || ! isset($candidate['id'])) {
                    continue;
                }

                $candidates[$candidate['id']] = $candidate;
            }
        }

        $bestCandidate = null;
        $bestScore = 0;
        $bestReason = null;

        foreach ($candidates as $candidate) {
            [$score, $reason] = $this->scoreEntityMatch($party, $candidate);

            if ($score > $bestScore) {
                $bestCandidate = $candidate;
                $bestScore = $score;
                $bestReason = $reason;
            }
        }

        if (! $bestCandidate || $bestScore < 80) {
            return null;
        }

        return [
            'id' => $bestCandidate['id'],
            'name' => $bestCandidate['name'] ?? null,
            'score' => $bestScore,
            'match_type' => $bestReason,
        ];
    }

    /**
     * @param  array<string, mixed>  $party
     * @return array<int, string>
     */
    protected function buildQueries(array $party): array
    {
        $queries = [];

        if (($party['vat_number'] ?? '') !== '') {
            $queries[] = "vat_number = '".$this->escapeQueryValue((string) $party['vat_number'])."'";
        }

        if (($party['tax_code'] ?? '') !== '') {
            $queries[] = "tax_code = '".$this->escapeQueryValue((string) $party['tax_code'])."'";
        }

        foreach ($this->significantNameTokens((string) ($party['name'] ?? '')) as $token) {
            $queries[] = "name contains '".$this->escapeQueryValue($token)."'";
        }

        return array_values(array_unique($queries));
    }

    /**
     * @param  array<string, mixed>  $party
     * @param  array<string, mixed>  $candidate
     * @return array{int, string|null}
     */
    protected function scoreEntityMatch(array $party, array $candidate): array
    {
        $partyVat = $this->normalizeFiscal((string) ($party['vat_number'] ?? ''));
        $partyTaxCode = $this->normalizeFiscal((string) ($party['tax_code'] ?? ''));
        $candidateVat = $this->normalizeFiscal((string) ($candidate['vat_number'] ?? ''));
        $candidateTaxCode = $this->normalizeFiscal((string) ($candidate['tax_code'] ?? ''));

        if ($partyVat !== '' && $candidateVat !== '' && $partyVat === $candidateVat) {
            return [100, 'vat_number'];
        }

        if ($partyTaxCode !== '' && $candidateTaxCode !== '' && $partyTaxCode === $candidateTaxCode) {
            return [100, 'tax_code'];
        }

        $similarity = $this->nameSimilarity((string) ($party['name'] ?? ''), (string) ($candidate['name'] ?? ''));

        return [(int) round($similarity), $similarity >= 80 ? 'name' : null];
    }

    /**
     * @return array<int, string>
     */
    protected function significantNameTokens(string $name): array
    {
        $normalized = preg_replace('/[^a-z0-9 ]/i', ' ', $this->normalizeName($name)) ?? '';
        $tokens = preg_split('/\s+/', trim($normalized)) ?: [];
        $tokens = array_values(array_filter($tokens, fn (string $token) => strlen($token) >= 4));

        return array_slice($tokens, 0, 3);
    }

    protected function nameSimilarity(string $left, string $right): float
    {
        $left = $this->normalizeName($left);
        $right = $this->normalizeName($right);

        if ($left === '' || $right === '') {
            return 0;
        }

        similar_text($left, $right, $similarity);

        return $similarity;
    }

    protected function normalizeName(string $value): string
    {
        $value = strtoupper($value);
        $value = preg_replace('/\b(SRL|S\.R\.L\.|SPA|S\.P\.A\.|SNC|S\.N\.C\.|SAS|S\.A\.S\.|SRLS|S\.R\.L\.S\.|PA)\b/', ' ', $value) ?? $value;
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    protected function normalizeFiscal(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]+/', '', $value) ?? '');
    }

    protected function escapeQueryValue(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }
}
