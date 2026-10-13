<?php

namespace App\Commands\Auth;

use App\Services\OAuthScopes;
use App\Services\TokenStore;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

class LoginCommand extends Command
{
    protected $signature = 'auth:login
        {--token= : Sign in with an access token instead of the browser}
        {--client-id= : Use your own OAuth app instead of the built-in one}
        {--client-secret= : Client secret of your own OAuth app}
        {--grant=* : Extra permissions besides read-only access, e.g. issued_documents.invoices:a (comma separated or repeated)}
        {--full : Ask for full access to everything instead of read-only}
        {--no-browser : Print the authorization URL without opening a browser}
        {--timeout=300 : Seconds to wait for the browser callback (own OAuth apps only)}';

    protected $description = 'Authenticate with Fatture in Cloud';

    public function handle(): int
    {
        $token = $this->option('token');

        if ($token) {
            return $this->loginWithToken($token);
        }

        $grant = $this->parseScopes($this->option('grant'));

        if ($grant === null) {
            return self::FAILURE;
        }

        $clientId = $this->option('client-id');
        $clientSecret = null;

        if ($clientId) {
            $clientSecret = $this->option('client-secret')
                ?: ($this->input->isInteractive() ? password(label: 'Enter your OAuth2 Client Secret') : null);
        }

        $base = $this->option('full') ? OAuthScopes::full() : OAuthScopes::readOnly();

        return $this->loginWithOAuth(OAuthScopes::merge($base, $grant), $clientId, $clientSecret);
    }

    /**
     * Validate scopes given as repeated and/or comma separated values.
     *
     * @return list<string>|null null when a scope is unknown
     */
    protected function parseScopes(array $values): ?array
    {
        $scopes = collect($values)
            ->flatMap(fn (string $value) => explode(',', $value))
            ->map(fn (string $scope) => trim($scope))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $unknown = array_diff($scopes, OAuthScopes::all());

        if ($unknown !== []) {
            $this->error('Unknown permission: '.implode(', ', $unknown).'.');
            $this->line('Available permissions (:r read, :a full access):');
            $this->line('  '.implode(' ', OAuthScopes::all()));

            return null;
        }

        return $scopes;
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
            // Permissions of a manual token are unknown: the API decides.
            'scopes' => null,
            'refresh_token' => null,
            'client_id' => null,
            'client_secret' => null,
            'expires_at' => null,
        ]);

        $this->info("Authenticated as {$name} ({$email})");
        $this->newLine();
        $this->line('Token stored in: '.TokenStore::configDir().'/auth.json');

        $this->promptForCompany($token);

        return self::SUCCESS;
    }

    /**
     * Sign in with OAuth. Without a client ID the built-in app is used; an app
     * without a client secret (a public client) uses the device code flow.
     */
    protected function loginWithOAuth(array $scopes, ?string $clientId = null, ?string $clientSecret = null, bool $chooseCompany = true): int
    {
        $customApp = $clientId && $clientId !== config('oauth.client_id');

        if (! $customApp) {
            $clientId = config('oauth.client_id');
            $clientSecret = config('oauth.client_secret');
        }

        if (! $clientId) {
            $this->error('OAuth app credentials are missing. Use --client-id, or sign in with --token.');

            return self::FAILURE;
        }

        $data = $clientSecret
            ? $this->authorizeWithCode($clientId, $clientSecret, $scopes)
            : $this->authorizeWithDeviceCode($clientId, $scopes);

        if ($data === null) {
            return self::FAILURE;
        }

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
            // The built-in app secret ships with the binary: storing it would
            // only leave a stale copy behind if the app is ever rotated.
            'client_secret' => $customApp ? ($clientSecret ?: null) : null,
            'user_name' => $name,
            'user_email' => $email,
            'scopes' => $scopes,
        ]);

        $this->info("Authenticated as {$name} ({$email})");
        $this->line('Permissions: '.implode(' ', $scopes));
        $this->newLine();
        $this->line('Token stored in: '.TokenStore::configDir().'/auth.json');

        if ($chooseCompany) {
            $this->promptForCompany($accessToken);
        }

        return self::SUCCESS;
    }

    /**
     * Authorization code flow: the browser redirects to a local callback.
     *
     * @return array<string, mixed>|null token response
     */
    protected function authorizeWithCode(string $clientId, string $clientSecret, array $scopes): ?array
    {
        $callbackPort = (int) config('oauth.redirect_port');
        $redirectUri = "http://localhost:{$callbackPort}/callback";

        $state = bin2hex(random_bytes(16));
        $authUrl = 'https://api-v2.fattureincloud.it/oauth/authorize?'
            .http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => implode(' ', $scopes),
                'state' => $state,
            ]);

        $this->info('Open this URL to authorize fic with your Fatture in Cloud account:');
        $this->line($authUrl);
        $this->newLine();

        $this->openBrowser($authUrl);

        $timeout = max(1, (int) $this->option('timeout'));

        $this->line("Waiting up to {$timeout}s for the authorization on port {$callbackPort}...");
        $this->line('Prefer a manual access token? Run: fic auth:login --token=YOUR_TOKEN (get one at https://secure.fattureincloud.it/api)');
        $this->newLine();

        $code = $this->waitForCallback($callbackPort, $state, $timeout);

        if (! $code) {
            $this->error('OAuth authorization failed or timed out. Run the command again.');

            return null;
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

            return null;
        }

        return $response->json();
    }

    /**
     * Device code flow: the user enters a short code on the Fatture in Cloud
     * site while the CLI polls for the token. Needs no client secret and no
     * local callback, so it also works on remote machines.
     *
     * @return array<string, mixed>|null token response
     */
    protected function authorizeWithDeviceCode(string $clientId, array $scopes): ?array
    {
        $response = Http::asJson()->post('https://api-v2.fattureincloud.it/oauth/device', [
            'client_id' => $clientId,
            'scope' => implode(' ', $scopes),
        ]);

        if ($response->failed()) {
            $this->error('Could not start the authorization: '.$response->json('error_description', $response->json('error', 'Unknown error')));

            return null;
        }

        $device = $response->json('data') ?? $response->json();
        $interval = max(1, (int) ($device['interval'] ?? 5));
        $deadline = time() + (int) ($device['expires_in'] ?? 300);

        $this->info('To authorize fic with your Fatture in Cloud account, open:');
        $this->line('  '.$device['verification_uri']);
        $this->info('and enter the code:');
        $this->line('  '.$device['user_code']);
        $this->newLine();

        $this->openBrowser($device['verification_uri']);

        $this->line('Waiting for the authorization (the code expires in '.(int) ($device['expires_in'] ?? 300).'s)...');
        $this->line('Prefer a manual access token? Run: fic auth:login --token=YOUR_TOKEN (get one at https://secure.fattureincloud.it/api)');
        $this->newLine();

        while (time() < $deadline) {
            sleep($interval);

            $response = Http::asJson()->post('https://api-v2.fattureincloud.it/oauth/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'client_id' => $clientId,
                'device_code' => $device['device_code'],
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            $error = $response->json('error');

            if ($error === 'authorization_pending') {
                continue;
            }

            if ($error === 'slow_down') {
                $interval += 5;

                continue;
            }

            $this->error($error === 'access_denied'
                ? 'Access was denied. Run the command again to retry.'
                : 'Authorization failed: '.$response->json('error_description', $error ?? 'Unknown error'));

            return null;
        }

        $this->error('The code expired before it was approved. Run the command again.');

        return null;
    }

    protected function openBrowser(string $url): void
    {
        if ($this->option('no-browser')) {
            return;
        }

        // cmd.exe ignores single quotes, so Windows needs double quotes plus
        // an empty title argument for `start`
        match (PHP_OS_FAMILY) {
            'Darwin' => exec('open '.escapeshellarg($url)),
            'Linux' => exec('xdg-open '.escapeshellarg($url).' >/dev/null 2>&1'),
            'Windows' => exec('start "" "'.$url.'"'),
            default => null,
        };
    }

    protected function waitForCallback(int $port, string $expectedState, int $timeout): ?string
    {
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

        if (! $socket) {
            $this->error("Could not start local server on port {$port}: {$errstr}");

            return null;
        }

        stream_set_timeout($socket, $timeout);
        $conn = @stream_socket_accept($socket, $timeout);

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

        if (! $this->input->isInteractive()) {
            $this->line('This account has several companies:');

            foreach ($choices as $label) {
                $this->line("  {$label}");
            }

            $this->line('Choose the default one with: fic company:set COMPANY_ID');

            return;
        }

        $selected = select(
            label: 'Select a default company',
            options: $choices,
        );

        TokenStore::setCompanyId((int) $selected);
        $companyName = $companies[array_search($selected, array_column($companies, 'id'))]['name'] ?? $selected;
        $this->info("Default company set: {$companyName} (ID: {$selected})");
    }
}
