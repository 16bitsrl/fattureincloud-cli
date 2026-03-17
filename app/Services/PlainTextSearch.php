<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class PlainTextSearch
{
    /**
     * @param  list<string>  $queries
     * @return array{data: array<int, array<string, mixed>>, total: int}|null
     */
    public static function run(string $command, int|string $companyId, int $page, int $perPage, array $queries): ?array
    {
        $items = [];
        $total = 0;

        foreach ($queries as $query) {
            $payload = static::runQuery($command, $companyId, $page, $perPage, $query);

            if ($payload === null) {
                return null;
            }

            foreach ($payload['data'] ?? [] as $item) {
                if (! isset($item['id'])) {
                    continue;
                }

                $items[$item['id']] = $item;
            }

            $total = max($total, (int) ($payload['total'] ?? 0));
        }

        return [
            'data' => array_values($items),
            'total' => count($items) ?: $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function runQuery(string $command, int|string $companyId, int $page, int $perPage, string $query): ?array
    {
        $process = new Process([
            PHP_BINARY,
            base_path('fic'),
            $command,
            "--company-id={$companyId}",
            "--page={$page}",
            "--per-page={$perPage}",
            "--q={$query}",
            '--json',
            '--no-interaction',
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $payload = json_decode($process->getOutput(), true);

        return is_array($payload) ? $payload : null;
    }
}
