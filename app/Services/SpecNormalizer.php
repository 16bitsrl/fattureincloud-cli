<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;

class SpecNormalizer
{
    /**
     * Normalize the OpenAPI spec by:
     * - Merging path-level parameters into each operation
     * - Resolving $ref for parameters at path level
     *
     * This is needed because spatie/laravel-openapi-cli only reads
     * operation-level parameters, but the FattureInCloud spec defines
     * path parameters (like company_id) at the path level.
     */
    public static function normalize(string $inputPath, string $outputPath): string
    {
        if (file_exists($outputPath) && filemtime($outputPath) >= filemtime($inputPath)) {
            return $outputPath;
        }

        $spec = Yaml::parseFile($inputPath);
        $spec = self::mergePathLevelParameters($spec);

        $outputDir = dirname($outputPath);
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        file_put_contents($outputPath, Yaml::dump($spec, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

        return $outputPath;
    }

    protected static function mergePathLevelParameters(array $spec): array
    {
        $httpMethods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];

        foreach ($spec['paths'] ?? [] as $path => $pathItem) {
            $pathLevelParams = $pathItem['parameters'] ?? [];

            if (empty($pathLevelParams)) {
                continue;
            }

            // Resolve any $ref in path-level parameters
            $resolvedPathParams = array_map(
                fn (array $param) => self::resolveRef($spec, $param),
                $pathLevelParams,
            );

            foreach ($httpMethods as $method) {
                if (! isset($pathItem[$method])) {
                    continue;
                }

                $operationParams = $pathItem[$method]['parameters'] ?? [];

                // Resolve any $ref in operation-level parameters
                $resolvedOpParams = array_map(
                    fn (array $param) => self::resolveRef($spec, $param),
                    $operationParams,
                );

                // Get existing operation param names to avoid duplicates
                $existingNames = array_map(
                    fn (array $p) => ($p['name'] ?? '').':'.($p['in'] ?? ''),
                    $resolvedOpParams,
                );

                // Merge path-level params that don't exist at operation level
                foreach ($resolvedPathParams as $pathParam) {
                    $key = ($pathParam['name'] ?? '').':'.($pathParam['in'] ?? '');
                    if (! in_array($key, $existingNames)) {
                        $resolvedOpParams[] = $pathParam;
                    }
                }

                $spec['paths'][$path][$method]['parameters'] = $resolvedOpParams;
            }

            // Remove path-level parameters (now merged into operations)
            unset($spec['paths'][$path]['parameters']);
        }

        return $spec;
    }

    protected static function resolveRef(array $spec, array $item): array
    {
        if (! isset($item['$ref'])) {
            return $item;
        }

        $ref = $item['$ref'];

        // Only handle internal refs (#/components/...)
        if (! str_starts_with($ref, '#/')) {
            return $item;
        }

        $parts = explode('/', ltrim($ref, '#/'));
        $resolved = $spec;

        foreach ($parts as $part) {
            $resolved = $resolved[$part] ?? null;
            if ($resolved === null) {
                return $item; // Can't resolve, return as-is
            }
        }

        return is_array($resolved) ? $resolved : $item;
    }
}
