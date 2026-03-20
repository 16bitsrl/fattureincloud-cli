<?php

namespace App\Commands;

use App\Services\PlainTextSearch;
use App\Services\TokenStore;
use LaravelZero\Framework\Commands\Command;

class ClientsSearchCommand extends Command
{
    protected $signature = 'clients:search
        {term : Free-text term to search in client name, vat number, or tax code}
        {--company-id= : Company ID (defaults to configured company)}
        {--page=1 : Page number}
        {--per-page=25 : Results per page}
        {--json : Output raw JSON}';

    protected $description = 'Search clients with plain text without writing Fatture in Cloud query syntax';

    public function handle(): int
    {
        $companyId = $this->option('company-id') ?: TokenStore::getCompanyId();

        if (! $companyId) {
            $this->error('No company selected. Use --company-id or run: fic company:set');

            return self::FAILURE;
        }

        $token = TokenStore::getAccessToken();

        if (! $token) {
            $this->error('Not authenticated. Run: fic auth:login');

            return self::FAILURE;
        }

        $term = trim((string) $this->argument('term'));

        if ($term === '') {
            $this->error('Search term cannot be empty.');

            return self::FAILURE;
        }

        $escapedTerm = str_replace("'", "''", $term);

        $payload = PlainTextSearch::run('api:list-clients', $companyId, (int) $this->option('page'), (int) $this->option('per-page'), [
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

        $rows = collect($payload['data'] ?? [])->map(fn (array $client) => [
            'id' => $client['id'] ?? null,
            'name' => $client['name'] ?? '',
            'vat_number' => $client['vat_number'] ?? '',
            'tax_code' => $client['tax_code'] ?? '',
            'city' => $client['address_city'] ?? '',
            'email' => $client['email'] ?? '',
        ])->all();

        if ($rows === []) {
            $this->info('No clients found.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'VAT number', 'Tax code', 'City', 'Email'], $rows);
        $this->line('Total: '.($payload['total'] ?? count($rows)));

        return self::SUCCESS;
    }
}
