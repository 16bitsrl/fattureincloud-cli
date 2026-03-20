<?php

namespace App\Commands;

use App\Services\PlainTextSearch;
use App\Services\TokenStore;
use LaravelZero\Framework\Commands\Command;

class SuppliersSearchCommand extends Command
{
    protected $signature = 'suppliers:search
        {term : Free-text term to search in supplier name, vat number, or tax code}
        {--company-id= : Company ID (defaults to configured company)}
        {--page=1 : Page number}
        {--per-page=25 : Results per page}
        {--json : Output raw JSON}';

    protected $description = 'Search suppliers with plain text without writing Fatture in Cloud query syntax';

    public function handle(): int
    {
        $companyId = $this->option('company-id') ?: TokenStore::getCompanyId();

        if (! $companyId) {
            $this->error('No company selected. Use --company-id or run: fic company:set');

            return self::FAILURE;
        }

        if (! TokenStore::getAccessToken()) {
            $this->error('Not authenticated. Run: fic auth:login');

            return self::FAILURE;
        }

        $term = trim((string) $this->argument('term'));

        if ($term === '') {
            $this->error('Search term cannot be empty.');

            return self::FAILURE;
        }

        $escapedTerm = str_replace("'", "''", $term);

        $payload = PlainTextSearch::run('api:list-suppliers', $companyId, (int) $this->option('page'), (int) $this->option('per-page'), [
            "name like '%{$escapedTerm}%'",
            "vat_number like '%{$escapedTerm}%'",
            "tax_code like '%{$escapedTerm}%'",
        ]);

        if ($payload === null) {
            $this->error('Search failed.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = collect($payload['data'] ?? [])->map(fn (array $supplier) => [
            'id' => $supplier['id'] ?? null,
            'name' => $supplier['name'] ?? '',
            'vat_number' => $supplier['vat_number'] ?? '',
            'tax_code' => $supplier['tax_code'] ?? '',
            'city' => $supplier['address_city'] ?? '',
            'email' => $supplier['email'] ?? '',
        ])->all();

        if ($rows === []) {
            $this->info('No suppliers found.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'VAT number', 'Tax code', 'City', 'Email'], $rows);
        $this->line('Total: '.($payload['total'] ?? count($rows)));

        return self::SUCCESS;
    }
}
