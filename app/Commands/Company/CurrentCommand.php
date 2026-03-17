<?php

namespace App\Commands\Company;

use App\Services\TokenStore;
use LaravelZero\Framework\Commands\Command;

class CurrentCommand extends Command
{
    protected $signature = 'company:current
        {--json : Output as JSON}';

    protected $description = 'Show the currently selected default company ID';

    public function handle(): int
    {
        $companyId = TokenStore::getCompanyId();

        if ($this->option('json')) {
            $this->line(json_encode(['company_id' => $companyId], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (! $companyId) {
            $this->warn('No default company set. Run: fic company:set');

            return self::FAILURE;
        }

        $this->info("Current company ID: {$companyId}");

        return self::SUCCESS;
    }
}
