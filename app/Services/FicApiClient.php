<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FicApiClient
{
    public function __construct(
        protected ?string $token = null,
        protected int $maxAttempts = 5,
    ) {
        $this->token ??= TokenStore::getAccessToken();
    }

    public function get(string $uri, array $query = []): Response
    {
        return $this->send('GET', $uri, [
            'query' => $query,
        ]);
    }

    public function post(string $uri, array $payload = []): Response
    {
        return $this->send('POST', $uri, [
            'json' => $payload,
        ]);
    }

    public function attach(string $uri, string $field, string $path, ?string $filename = null, array $payload = []): Response
    {
        if (! is_file($path)) {
            throw new RuntimeException("Attachment file not found: {$path}");
        }

        $attempt = 0;

        do {
            $attempt++;

            $response = $this->requestBuilder()
                ->attach($field, file_get_contents($path), $filename ?: basename($path))
                ->post($this->url($uri), $payload);

            if (! $this->shouldRetry($response, $attempt)) {
                return $response;
            }

            $this->sleepBeforeRetry($response, $attempt);
        } while ($attempt < $this->maxAttempts);

        return $response;
    }

    public function errorMessage(Response $response): string
    {
        $payload = $response->json();

        if (is_array($payload)) {
            $message = $payload['error']['message']
                ?? $payload['message']
                ?? $payload['error_description']
                ?? null;

            if (is_string($message) && $message !== '') {
                $details = [];

                foreach (($payload['error']['validation_result'] ?? []) as $field => $errors) {
                    if (! is_array($errors)) {
                        continue;
                    }

                    foreach ($errors as $error) {
                        if (is_string($error) && $error !== '') {
                            $details[] = "{$field}: {$error}";
                        }
                    }
                }

                return $details === []
                    ? $message
                    : $message.' ('.implode('; ', $details).')';
            }
        }

        return "HTTP {$response->status()}";
    }

    protected function send(string $method, string $uri, array $options = []): Response
    {
        $attempt = 0;

        do {
            $attempt++;

            $builder = $this->requestBuilder();

            if (array_key_exists('json', $options)) {
                $builder = $builder->asJson();
            }

            $response = $builder->send($method, $this->url($uri), $options);

            if (! $this->shouldRetry($response, $attempt)) {
                return $response;
            }

            $this->sleepBeforeRetry($response, $attempt);
        } while ($attempt < $this->maxAttempts);

        return $response;
    }

    protected function requestBuilder()
    {
        if (! $this->token) {
            throw new RuntimeException('Missing Fatture in Cloud access token. Run: fic auth:login');
        }

        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout(60);
    }

    protected function url(string $uri): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        return 'https://api-v2.fattureincloud.it/'.ltrim($uri, '/');
    }

    protected function shouldRetry(Response $response, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        if ($response->status() === 429) {
            return true;
        }

        return $response->status() === 403 && $response->header('Retry-After') !== null;
    }

    protected function sleepBeforeRetry(Response $response, int $attempt): void
    {
        $retryAfter = (int) $response->header('Retry-After', 0);

        if ($retryAfter > 0) {
            sleep(min($retryAfter, 30));

            return;
        }

        usleep(min(2 ** $attempt, 8) * 1_000_000);
    }
}
