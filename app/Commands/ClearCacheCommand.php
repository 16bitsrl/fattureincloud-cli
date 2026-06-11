<?php

namespace App\Commands;

use App\Services\TokenStore;
use LaravelZero\Framework\Commands\Command;

class ClearCacheCommand extends Command
{
    protected $signature = 'clear-cache';

    protected $description = 'Clear cached spec and temporary files';

    public function handle(): int
    {
        $dirs = [
            // Spec cache from older versions of the CLI
            TokenStore::configDir().DIRECTORY_SEPARATOR.'cache',
            storage_path('framework/cache'),
        ];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            foreach (glob($dir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        $this->info('Cache cleared.');

        return self::SUCCESS;
    }
}
