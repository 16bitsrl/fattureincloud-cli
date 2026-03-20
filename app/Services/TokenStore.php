<?php

namespace App\Services;

class TokenStore
{
    protected static ?string $configDir = null;

    public static function configDir(): string
    {
        if (static::$configDir) {
            return static::$configDir;
        }

        $home = match (true) {
            PHP_OS_FAMILY === 'Windows' => getenv('USERPROFILE') ?: getenv('HOMEDRIVE').getenv('HOMEPATH'),
            default => getenv('HOME') ?: getenv('XDG_CONFIG_HOME') ?: '/tmp',
        };

        $dir = rtrim($home, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.config'.DIRECTORY_SEPARATOR.'fattureincloud-cli';

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    public static function setConfigDir(string $dir): void
    {
        static::$configDir = $dir;
    }

    protected static function authPath(): string
    {
        return static::configDir().DIRECTORY_SEPARATOR.'auth.json';
    }

    protected static function configPath(): string
    {
        return static::configDir().DIRECTORY_SEPARATOR.'config.json';
    }

    public static function save(array $data): void
    {
        $existing = static::load();
        $merged = array_merge($existing, $data);

        file_put_contents(
            static::authPath(),
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        chmod(static::authPath(), 0600);
    }

    public static function load(): array
    {
        $path = static::authPath();

        if (! file_exists($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        return json_decode($contents, true) ?: [];
    }

    public static function getAccessToken(): ?string
    {
        return static::load()['access_token'] ?? null;
    }

    public static function getRefreshToken(): ?string
    {
        return static::load()['refresh_token'] ?? null;
    }

    public static function getClientId(): ?string
    {
        return static::load()['client_id'] ?? null;
    }

    public static function getClientSecret(): ?string
    {
        return static::load()['client_secret'] ?? null;
    }

    public static function isAuthenticated(): bool
    {
        return static::getAccessToken() !== null;
    }

    public static function clear(): void
    {
        $path = static::authPath();

        if (file_exists($path)) {
            unlink($path);
        }
    }

    // --- Config (company context, preferences) ---

    public static function saveConfig(array $data): void
    {
        $existing = static::loadConfig();
        $merged = array_merge($existing, $data);

        file_put_contents(
            static::configPath(),
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    public static function loadConfig(): array
    {
        $path = static::configPath();

        if (! file_exists($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        return json_decode($contents, true) ?: [];
    }

    public static function getCompanyId(): ?int
    {
        $config = static::loadConfig();

        return isset($config['company_id']) ? (int) $config['company_id'] : null;
    }

    public static function setCompanyId(int $companyId): void
    {
        static::saveConfig(['company_id' => $companyId]);
    }
}
