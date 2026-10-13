<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class Releases
{
    public const REPO = '16bitsrl/fattureincloud-cli';

    protected const CHECK_INTERVAL = 86400;

    public static function latestVersion(int $timeout = 15): ?string
    {
        try {
            $tag = Http::timeout($timeout)
                ->get('https://api.github.com/repos/'.self::REPO.'/releases/latest')
                ->json('tag_name');
        } catch (Throwable) {
            return null;
        }

        return is_string($tag) ? ltrim($tag, 'v') : null;
    }

    /**
     * Message about a newer version, checked on GitHub at most once a day.
     * Never slows a command down by more than a couple of seconds.
     */
    public static function updateNotice(Installation $installation): ?string
    {
        if (getenv('FIC_NO_UPDATE_CHECK') || getenv('CI') || $installation->manager === Installation::SOURCE) {
            return null;
        }

        $cache = TokenStore::configDir().DIRECTORY_SEPARATOR.'update-check.json';
        $cached = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;

        if (is_array($cached) && time() - (int) ($cached['checked_at'] ?? 0) < self::CHECK_INTERVAL) {
            $latest = $cached['latest'] ?? null;
        } else {
            $latest = self::latestVersion(timeout: 2);
            @file_put_contents($cache, json_encode(['checked_at' => time(), 'latest' => $latest]));
        }

        $current = config('app.version');

        if (! is_string($latest) || version_compare($latest, $current, '<=')) {
            return null;
        }

        $upgrade = $installation->manager === Installation::COMPOSER
            ? 'composer global update '.self::REPO
            : 'fic upgrade';

        return "fic {$latest} is available (installed: {$current}). Upgrade with: {$upgrade}";
    }
}
