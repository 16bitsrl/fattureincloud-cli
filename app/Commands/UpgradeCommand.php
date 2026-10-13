<?php

namespace App\Commands;

use App\Services\Installation;
use App\Services\Releases;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use Phar;
use Throwable;

class UpgradeCommand extends Command
{
    protected $signature = 'upgrade
        {version? : Version to install, e.g. 1.2.0 (default: latest)}
        {--check : Only tell whether a newer version is available}';

    protected $description = 'Upgrade fic to the latest version';

    public function handle(Installation $installation): int
    {
        $current = config('app.version');
        $requested = $this->argument('version');
        $target = $requested !== null ? ltrim($requested, 'v') : Releases::latestVersion();

        if ($target === null) {
            $this->error('Could not check the latest version on GitHub. Try again later.');

            return self::FAILURE;
        }

        if ($requested === null && version_compare($target, $current, '<=')) {
            $this->info("fic {$current} is up to date.");

            return self::SUCCESS;
        }

        if ($this->option('check')) {
            $this->line("fic {$target} is available (installed: {$current}). Upgrade with: fic upgrade");

            return self::SUCCESS;
        }

        if ($installation->manager === Installation::COMPOSER) {
            $this->error('fic was installed with Composer. Upgrade with: composer global update '.Releases::REPO);

            return self::FAILURE;
        }

        if ($installation->manager === Installation::SOURCE) {
            $this->error('fic is running from source. Update it with: git pull');

            return self::FAILURE;
        }

        if ($installation->asset === null) {
            $this->error('No release is available for this platform.');

            return self::FAILURE;
        }

        $this->line("Downloading fic {$target}...");

        $binary = $this->download($target, $installation->asset);
        $checksums = $this->download($target, 'checksums.txt');

        if ($binary === null || $checksums === null) {
            $this->error("Could not download fic {$target}. Check that the version exists: https://github.com/".Releases::REPO.'/releases');

            return self::FAILURE;
        }

        if (hash('sha256', $binary) !== $this->expectedChecksum($checksums, $installation->asset)) {
            $this->error('The download does not match its checksum: fic was not changed.');

            return self::FAILURE;
        }

        if (! $this->replace($installation->path, $binary)) {
            $this->error('Could not replace '.$installation->path.'. Check that you can write to '.dirname($installation->path).'.');

            return self::FAILURE;
        }

        $this->info("Upgraded fic {$current} to {$target}.");

        // The running PHAR was just replaced: stop before PHP loads anything else from it.
        if (Phar::running(false) !== '') {
            exit(self::SUCCESS);
        }

        return self::SUCCESS;
    }

    protected function download(string $version, string $file): ?string
    {
        try {
            $response = Http::timeout(300)
                ->get('https://github.com/'.Releases::REPO."/releases/download/v{$version}/{$file}");
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    protected function expectedChecksum(string $checksums, string $asset): ?string
    {
        foreach (preg_split('/\R/', $checksums) as $line) {
            $parts = preg_split('/\s+/', trim($line));

            if (count($parts) === 2 && ltrim($parts[1], '*') === $asset) {
                return strtolower($parts[0]);
            }
        }

        return null;
    }

    /**
     * Write the new binary next to the current one, then swap them with a
     * rename so an interrupted upgrade never leaves a broken fic behind.
     */
    protected function replace(string $path, string $binary): bool
    {
        $new = $path.'.new';
        $old = $path.'.old';

        // Left over from the previous upgrade on Windows.
        @unlink($old);

        if (@file_put_contents($new, $binary) === false) {
            return false;
        }

        @chmod($new, fileperms($path) & 0777);

        // Windows cannot overwrite a running executable, but can rename it.
        if (PHP_OS_FAMILY === 'Windows' && ! @rename($path, $old)) {
            @unlink($new);

            return false;
        }

        if (! @rename($new, $path)) {
            @unlink($new);

            return false;
        }

        return true;
    }
}
