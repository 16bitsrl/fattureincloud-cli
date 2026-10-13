<?php

namespace App\Commands\Auth;

use App\Services\OAuthScopes;
use App\Services\TokenRefresher;
use App\Services\TokenStore;

class GrantCommand extends LoginCommand
{
    protected $signature = 'auth:grant
        {scopes* : Permissions to add, e.g. issued_documents.invoices:a}
        {--no-browser : Print the authorization URL without opening a browser}
        {--timeout=300 : Seconds to wait for the browser callback (own OAuth apps only)}';

    protected $description = 'Ask for extra permissions on top of the current ones';

    public function handle(): int
    {
        $grant = $this->parseScopes($this->argument('scopes'));

        if ($grant === null) {
            return self::FAILURE;
        }

        $auth = TokenStore::load();
        $current = $auth['scopes'] ?? OAuthScopes::readOnly();
        $clientId = $auth['client_id'] ?? null;

        return $this->loginWithOAuth(
            OAuthScopes::merge($current, $grant),
            $clientId,
            TokenRefresher::clientSecret($auth),
            chooseCompany: false,
        );
    }
}
