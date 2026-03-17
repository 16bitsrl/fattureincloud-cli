<?php

namespace App\Commands\Auth;

use App\Services\TokenStore;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    protected $signature = 'auth:status
        {--json : Output as JSON}';

    protected $description = 'Show current authentication status';

    public function handle(): int
    {
        $auth = TokenStore::load();
        $config = TokenStore::loadConfig();

        $status = [
            'authenticated' => TokenStore::isAuthenticated(),
            'user_name' => $auth['user_name'] ?? null,
            'user_email' => $auth['user_email'] ?? null,
            'has_refresh_token' => isset($auth['refresh_token']),
            'company_id' => $config['company_id'] ?? null,
            'config_dir' => TokenStore::configDir(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (! $status['authenticated']) {
            $this->error('Not authenticated. Run: fic auth:login');

            return self::FAILURE;
        }

        $this->info('Authenticated');
        $this->newLine();
        $this->line("  User:       {$status['user_name']}");
        $this->line("  Email:      {$status['user_email']}");
        $this->line("  Company ID: ".($status['company_id'] ?? '<not set>'));
        $this->line("  Refresh:    ".($status['has_refresh_token'] ? 'yes' : 'no'));
        $this->line("  Config:     {$status['config_dir']}");

        return self::SUCCESS;
    }
}
