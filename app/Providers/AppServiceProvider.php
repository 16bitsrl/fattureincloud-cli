<?php

namespace App\Providers;

use App\Services\SpecNormalizer;
use App\Services\TokenStore;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\ServiceProvider;
use Spatie\OpenApiCli\Facades\OpenApiCli;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Use a writable location for the normalized spec (PHAR can't write inside itself)
        $cacheDir = TokenStore::configDir().DIRECTORY_SEPARATOR.'cache';
        $specPath = SpecNormalizer::normalize(
            resource_path('openapi/fattureincloud.yaml'),
            $cacheDir.DIRECTORY_SEPARATOR.'fattureincloud-normalized.yaml',
        );

        OpenApiCli::register(
            specPath: $specPath,
            namespace: 'fic',
        )
            ->baseUrl('https://api-v2.fattureincloud.it')
            ->useOperationIds()
            ->cache(ttl: 86400)
            ->jsonOutput()
            ->followRedirects()
            ->auth(function () {
                $token = TokenStore::getAccessToken();

                if (! $token) {
                    return null;
                }

                return $token;
            })
            ->onError(function (Response $response, Command $command) {
                return match ($response->status()) {
                    401 => $command->error('Authentication failed. Run: fic auth:login'),
                    403 => $command->error('Permission denied. Check your token scopes.'),
                    429 => $command->warn('Rate limited. Retry after '.$response->header('Retry-After', '60').'s.'),
                    default => false,
                };
            });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TokenStore::class);
    }
}
