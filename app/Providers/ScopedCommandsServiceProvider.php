<?php

namespace App\Providers;

use App\OpenApi\ScopedEndpointCommand;
use Illuminate\Support\ServiceProvider;
use Spatie\OpenApiCli\Commands\EndpointCommand;

/**
 * Swaps the generated API commands for scope-checking ones. Must be registered
 * after OpenApiCliServiceProvider, which binds the commands while booting.
 */
class ScopedCommandsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (array_keys($this->app->getBindings()) as $key) {
            if (str_starts_with($key, 'openapi.')) {
                $this->app->extend($key, fn ($command) => $command instanceof EndpointCommand
                    ? ScopedEndpointCommand::wrap($command)
                    : $command);
            }
        }
    }
}
