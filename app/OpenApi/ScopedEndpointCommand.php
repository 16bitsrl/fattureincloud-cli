<?php

namespace App\OpenApi;

use App\Services\OAuthScopes;
use App\Services\TokenStore;
use Spatie\OpenApiCli\Commands\EndpointCommand;

/**
 * An API command that checks the granted OAuth scopes before calling the API,
 * so a missing permission ends with the exact `fic auth:grant` to run.
 */
class ScopedEndpointCommand extends EndpointCommand
{
    public static function wrap(EndpointCommand $command): self
    {
        return new self(
            $command->config,
            $command->method,
            $command->path,
            $command->operationData,
            substr($command->getName(), strlen($command->config->getNamespace()) + 1),
        );
    }

    public function handle(): int
    {
        // Manual tokens and logins made before scopes were tracked: unknown, let the API decide.
        $granted = TokenStore::load()['scopes'] ?? null;

        if (is_array($granted)) {
            $missing = OAuthScopes::missing(
                OAuthScopes::required($this->operationData, $this->documentType()),
                $granted,
            );

            if ($missing !== []) {
                $this->error(count($missing) === 1
                    ? "Missing permission: this command needs {$missing[0]}."
                    : 'Missing permission: this command needs one of '.implode(', ', $missing).'.');
                $this->line('Grant it with: fic auth:grant '.$missing[0]);
                $this->line('Approve it with the link and code it prints, then run this command again.');

                return self::FAILURE;
            }
        }

        return parent::handle();
    }

    protected function documentType(): ?string
    {
        if ($this->hasOption('type') && is_string($this->option('type'))) {
            return $this->option('type');
        }

        $input = json_decode((string) $this->option('input'), true);

        return is_array($input) ? ($input['data']['type'] ?? null) : null;
    }
}
