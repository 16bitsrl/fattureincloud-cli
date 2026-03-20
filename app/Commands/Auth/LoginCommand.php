<?php

namespace App\Commands\Auth;

use App\Services\TokenStore;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class LoginCommand extends Command
{
    protected $signature = 'auth:login
        {--token= : Provide an access token directly (skips OAuth flow)}
        {--client-id= : OAuth2 client ID}
        {--client-secret= : OAuth2 client secret}';

    protected $description = 'Authenticate with Fatture in Cloud';

    public function handle(): int
    {
        $token = $this->option('token');

        if ($token) {
            return $this->loginWithToken($token);
        }

        $method = select(
            label: 'How would you like to authenticate?',
            options: [
                'token' => 'Access token (manual / device token)',
                'oauth' => 'OAuth2 authorization code flow',
            ],
        );

        return match ($method) {
            'token' => $this->loginWithTokenPrompt(),
            'oauth' => $this->loginWithOAuth(),
        };
    }

    protected function loginWithTokenPrompt(): int
    {
        $token = password(
            label: 'Enter your Fatture in Cloud access token',
            hint: 'Get one at https://secure.fattureincloud.it/api',
        );

        return $this->loginWithToken($token);
    }

    protected function loginWithToken(string $token): int
    {
        $this->info('Validating token...');

        $response = Http::withToken($token)
            ->get('https://api-v2.fattureincloud.it/user/info');

        if ($response->failed()) {
            $this->error('Invalid token. Authentication failed.');

            return self::FAILURE;
        }

        $user = $response->json('data');
        $name = trim(($user['name'] ?? '').' '.($user['surname'] ?? ''));
        $email = $user['email'] ?? 'unknown';

        TokenStore::save([
            'access_token' => $token,
            'user_name' => $name,
            'user_email' => $email,
        ]);

        $this->info("Authenticated as {$name} ({$email})");
        $this->newLine();
        $this->line('Token stored in: '.TokenStore::configDir().'/auth.json');

        $this->promptForCompany($token);

        return self::SUCCESS;
    }

    protected function loginWithOAuth(): int
    {
        $clientId = $this->option('client-id') ?: text(
            label: 'Enter your OAuth2 Client ID',
            hint: 'From https://secure.fattureincloud.it/api',
        );

        $clientSecret = $this->option('client-secret') ?: password(
            label: 'Enter your OAuth2 Client Secret',
        );

        $callbackPort = 8511;
        $redirectUri = "http://localhost:{$callbackPort}/callback";

        $scopes = implode(' ', [
            'entity.clients:r', 'entity.clients:a',
            'entity.suppliers:r', 'entity.suppliers:a',
            'products:r', 'products:a',
            'issued_documents.invoices:r', 'issued_documents.invoices:a',
            'issued_documents.credit_notes:r', 'issued_documents.credit_notes:a',
            'issued_documents.receipts:r', 'issued_documents.receipts:a',
            'issued_documents.orders:r', 'issued_documents.orders:a',
            'issued_documents.quotes:r', 'issued_documents.quotes:a',
            'issued_documents.proformas:r', 'issued_documents.proformas:a',
            'issued_documents.delivery_notes:r', 'issued_documents.delivery_notes:a',
            'received_documents:r', 'received_documents:a',
            'taxes:r', 'taxes:a',
            'archive:r', 'archive:a',
            'cashbook:r', 'cashbook:a',
            'settings:r', 'settings:a',
            'situation:r',
        ]);

        $state = bin2hex(random_bytes(16));
        $authUrl = 'https://api-v2.fattureincloud.it/oauth/authorize?'
            .http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => $scopes,
                'state' => $state,
            ]);

        $this->info('Opening browser for authorization...');
        $this->line($authUrl);
        $this->newLine();

        // Try to open browser
        match (PHP_OS_FAMILY) {
            'Darwin' => exec("open '{$authUrl}'"),
            'Linux' => exec("xdg-open '{$authUrl}'"),
            'Windows' => exec("start '{$authUrl}'"),
            default => null,
        };

        $this->line("Waiting for callback on port {$callbackPort}...");
        $this->line('If the browser did not open, visit the URL above manually.');
        $this->newLine();

        $code = $this->waitForCallback($callbackPort, $state);

        if (! $code) {
            $this->error('OAuth authorization failed or timed out.');

            return self::FAILURE;
        }

        $this->info('Authorization code received. Exchanging for token...');

        $response = Http::post('https://api-v2.fattureincloud.it/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if ($response->failed()) {
            $this->error('Token exchange failed: '.$response->json('error_description', 'Unknown error'));

            return self::FAILURE;
        }

        $data = $response->json();
        $accessToken = $data['access_token'];

        // Fetch user info
        $userResponse = Http::withToken($accessToken)
            ->get('https://api-v2.fattureincloud.it/user/info');

        $user = $userResponse->json('data') ?? [];
        $name = trim(($user['name'] ?? '').' '.($user['surname'] ?? ''));
        $email = $user['email'] ?? 'unknown';

        TokenStore::save([
            'access_token' => $accessToken,
            'refresh_token' => $data['refresh_token'] ?? null,
            'token_type' => $data['token_type'] ?? 'bearer',
            'expires_in' => $data['expires_in'] ?? null,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'user_name' => $name,
            'user_email' => $email,
        ]);

        $this->info("Authenticated as {$name} ({$email})");
        $this->newLine();
        $this->line('Token stored in: '.TokenStore::configDir().'/auth.json');

        $this->promptForCompany($accessToken);

        return self::SUCCESS;
    }

    protected function waitForCallback(int $port, string $expectedState): ?string
    {
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

        if (! $socket) {
            $this->error("Could not start local server on port {$port}: {$errstr}");

            return null;
        }

        // Wait up to 120 seconds
        stream_set_timeout($socket, 120);
        $conn = @stream_socket_accept($socket, 120);

        if (! $conn) {
            fclose($socket);

            return null;
        }

        $request = fread($conn, 8192);
        preg_match('/GET \/callback\?(.+?) HTTP/', $request, $matches);
        parse_str($matches[1] ?? '', $params);

        $code = $params['code'] ?? null;
        $returnedState = $params['state'] ?? null;

        if ($returnedState !== $expectedState) {
            $html = '<html><body><h1>Error</h1><p>State mismatch. Please try again.</p></body></html>';
            fwrite($conn, "HTTP/1.1 400 Bad Request\r\nContent-Type: text/html\r\nContent-Length: ".strlen($html)."\r\nConnection: close\r\n\r\n".$html);
            fclose($conn);
            fclose($socket);

            return null;
        }

        $html = '<html><body><h1>Authenticated!</h1><p>You can close this window and return to the terminal.</p></body></html>';
        fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: ".strlen($html)."\r\nConnection: close\r\n\r\n".$html);
        fclose($conn);
        fclose($socket);

        return $code;
    }

    protected function promptForCompany(string $token): void
    {
        $this->newLine();
        $this->info('Fetching your companies...');

        $response = Http::withToken($token)
            ->get('https://api-v2.fattureincloud.it/user/companies');

        if ($response->failed()) {
            $this->warn('Could not fetch companies. Set one later with: fic company:set');

            return;
        }

        $companies = $response->json('data.companies', []);

        if (empty($companies)) {
            $this->warn('No companies found on this account.');

            return;
        }

        if (count($companies) === 1) {
            $company = $companies[0];
            TokenStore::setCompanyId($company['id']);
            $this->info("Default company set: {$company['name']} (ID: {$company['id']})");

            return;
        }

        $choices = collect($companies)->mapWithKeys(fn ($c) => [
            $c['id'] => "{$c['name']} (ID: {$c['id']})",
        ])->all();

        $selected = select(
            label: 'Select a default company',
            options: $choices,
        );

        TokenStore::setCompanyId((int) $selected);
        $companyName = $companies[array_search($selected, array_column($companies, 'id'))]['name'] ?? $selected;
        $this->info("Default company set: {$companyName} (ID: {$selected})");
    }
}
