<?php

namespace App\Commands\Company;

use App\Services\TokenStore;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\select;

class SetCommand extends Command
{
    protected $signature = 'company:set
        {company_id? : The company ID to set as default}';

    protected $description = 'Set the default company ID for API calls';

    public function handle(): int
    {
        $companyId = $this->argument('company_id');

        if ($companyId) {
            TokenStore::setCompanyId((int) $companyId);
            $this->info("Default company set to ID: {$companyId}");

            return self::SUCCESS;
        }

        if (! TokenStore::isAuthenticated()) {
            $this->error('Not authenticated. Run: fic auth:login');

            return self::FAILURE;
        }

        $response = Http::withToken(TokenStore::getAccessToken())
            ->get('https://api-v2.fattureincloud.it/user/companies');

        if ($response->failed()) {
            $this->error('Could not fetch companies.');

            return self::FAILURE;
        }

        $companies = $response->json('data.companies', []);

        if (empty($companies)) {
            $this->warn('No companies found on this account.');

            return self::FAILURE;
        }

        $choices = collect($companies)->mapWithKeys(fn ($c) => [
            $c['id'] => "{$c['name']} (ID: {$c['id']})",
        ])->all();

        $selected = select(
            label: 'Select a default company',
            options: $choices,
        );

        TokenStore::setCompanyId((int) $selected);
        $this->info("Default company set to ID: {$selected}");

        return self::SUCCESS;
    }
}
