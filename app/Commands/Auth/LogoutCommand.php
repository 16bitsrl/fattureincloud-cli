<?php

namespace App\Commands\Auth;

use App\Services\TokenStore;
use LaravelZero\Framework\Commands\Command;

class LogoutCommand extends Command
{
    protected $signature = 'auth:logout';

    protected $description = 'Remove stored authentication credentials';

    public function handle(): int
    {
        if (! TokenStore::isAuthenticated()) {
            $this->info('Not currently authenticated.');

            return self::SUCCESS;
        }

        $auth = TokenStore::load();
        $name = $auth['user_name'] ?? 'unknown';

        TokenStore::clear();

        $this->info("Logged out ({$name}). Token removed.");

        return self::SUCCESS;
    }
}
