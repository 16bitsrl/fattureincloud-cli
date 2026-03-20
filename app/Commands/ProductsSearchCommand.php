<?php

namespace App\Commands;

use App\Services\PlainTextSearch;
use App\Services\TokenStore;
use LaravelZero\Framework\Commands\Command;

class ProductsSearchCommand extends Command
{
    protected $signature = 'products:search
        {term : Free-text term to search in product name or code}
        {--company-id= : Company ID (defaults to configured company)}
        {--page=1 : Page number}
        {--per-page=25 : Results per page}
        {--json : Output raw JSON}';

    protected $description = 'Search products with plain text without writing Fatture in Cloud query syntax';

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

        $payload = PlainTextSearch::run('api:list-products', $companyId, (int) $this->option('page'), (int) $this->option('per-page'), [
            "name like '%{$escapedTerm}%'",
            "code like '%{$escapedTerm}%'",
        ]);

        if ($payload === null) {
            $this->error('Search failed.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = collect($payload['data'] ?? [])->map(fn (array $product) => [
            'id' => $product['id'] ?? null,
            'name' => $product['name'] ?? '',
            'code' => $product['code'] ?? '',
            'category' => $product['category'] ?? '',
            'net_price' => $product['net_price'] ?? '',
            'stock' => $product['stock_quantity'] ?? '',
        ])->all();

        if ($rows === []) {
            $this->info('No products found.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Code', 'Category', 'Net price', 'Stock'], $rows);
        $this->line('Total: '.($payload['total'] ?? count($rows)));

        return self::SUCCESS;
    }
}
