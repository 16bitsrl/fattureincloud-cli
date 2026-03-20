<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class ClearCacheCommand extends Command
{
    protected $signature = 'clear-cache';

    protected $description = 'Clear cached OpenAPI spec and temporary files';

    public function handle(): int
    {
        // Clear any cached spec files
        $cacheDir = storage_path('framework/cache');
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir.'/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        $this->info('Cache cleared.');

        return self::SUCCESS;
    }
}
