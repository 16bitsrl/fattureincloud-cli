<?php

namespace App\Services;

use Phar;

/**
 * Where the running fic lives and which release file replaces it.
 */
class Installation
{
    public const COMPOSER = 'composer';

    public const SOURCE = 'source';

    /**
     * @param  string|null  $asset  release file to download, null when fic cannot replace itself
     * @param  string|null  $manager  what updates this copy instead of `fic upgrade`
     */
    public function __construct(
        public readonly ?string $path,
        public readonly ?string $asset,
        public readonly ?string $manager = null,
    ) {}

    public static function detect(): self
    {
        $phar = Phar::running(false);

        if ($phar === '') {
            return new self(null, null, self::SOURCE);
        }

        if (str_contains($phar, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
            return new self($phar, null, self::COMPOSER);
        }

        // Static binaries are the PHP "micro" runtime with the PHAR appended.
        if (PHP_SAPI === 'micro') {
            return new self($phar, self::binaryAsset(PHP_OS_FAMILY, php_uname('m')));
        }

        return new self($phar, 'fic');
    }

    public static function binaryAsset(string $osFamily, string $machine): ?string
    {
        $os = match ($osFamily) {
            'Darwin' => 'macos',
            'Linux' => 'linux',
            'Windows' => 'windows',
            default => null,
        };

        $arch = match (strtolower($machine)) {
            'x86_64', 'amd64' => 'x86_64',
            'arm64', 'aarch64' => 'aarch64',
            default => null,
        };

        if ($os === null || $arch === null) {
            return null;
        }

        return "fic-{$os}-{$arch}".($os === 'windows' ? '.exe' : '');
    }
}
