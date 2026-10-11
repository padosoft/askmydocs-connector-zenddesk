<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Http;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorZendesk\Exceptions\ZendeskApiException;

/**
 * Thin, read-only HTTP client for the Zendesk Support and Help Center APIs.
 *
 * Authentication follows the operator's choice stored on the installation:
 *   - `api_token`   → Basic auth with `{email}/token` as the username and the
 *                     API token as the password (Zendesk's documented format);
 *   - `oauth_token` → `Authorization: Bearer {access_token}`.
 *
 * Every response body is streamed and capped, JSON is decoded strictly, 5xx
 * and connection failures are retried a bounded number of times, and a 429
 * surfaces its Retry-After so the sync engine can defer the batch instead of
 * burning the account's per-minute allowance.
 */
final class ZendeskClient
{
    public const AUTH_API_TOKEN = 'api_token';

    public const AUTH_OAUTH_TOKEN = 'oauth_token';

    private string $subdomain;

    public int $physicalRequests = 0;

    public function __construct(
        string $subdomain,
        private readonly string $secret,
        private readonly string $authMode = self::AUTH_API_TOKEN,
        private readonly string $email = '',
        private readonly ?Closure $beforeRequest = null,
    ) {
        $this->subdomain = UrlPolicy::subdomain($subdomain);
        if (trim($this->secret) === '') {
            throw new \InvalidArgumentException('Enter a Zendesk API token or OAuth access token.');
        }
        if (! in_array($this->authMode, [self::AUTH_API_TOKEN, self::AUTH_OAUTH_TOKEN], true)) {
            throw new \InvalidArgumentException('Unknown Zendesk authentication mode.');
        }
        if ($this->authMode === self::AUTH_API_TOKEN && filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Enter the email address of the Zendesk agent that owns the API token.');
        }
    }

    public function subdomain(): string
    {
        return $this->subdomain;
    }

    public function host(): string
    {
        return UrlPolicy::host($this->subdomain);
    }

    /**
     * GET an API path under /api/v2 and return the decoded JSON object.
     *
     * @param  array<string|int,mixed>  $query
     * @return array<string|int,mixed>
     */
    public function get(string $path, array $query = []): array
    {
        // Lowercase letters, digits, underscore, hyphen (locales such as en-us) and slashes: no dots, queries or traversal.
        if (preg_match('~^/[a-z0-9_/-]+$~D', $path) !== 1 || str_contains($path, '//')) {
            throw new \InvalidArgumentException('Invalid Zendesk API path.');
        }
        $url = 'https://'.$this->host().'/api/v2'.$path;
        $options = UrlPolicy::options($url) + ['allow_redirects' => false, 'stream' => true];
        $attempts = max(1, min(3, (int) config('connector-zendesk.http.attempts', 3)));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->countRequest();
            try {
                $response = $this->authenticated(Http::acceptJson())
                    ->connectTimeout((int) config('connector-zendesk.http.connect_timeout', 5))
                    ->timeout((int) config('connector-zendesk.http.timeout', 30))
                    ->withOptions($options)->get($url, $query);
            } catch (ConnectionException) {
                if ($attempt === $attempts) {
                    throw new \RuntimeException('Zendesk connection timed out or could not be established.');
                }

                continue;
            }
            if ($response->serverError() && $attempt < $attempts) {
                continue;
            }
            if (! $response->successful()) {
                throw new ZendeskApiException($response->status(), self::retryAfter($response), self::validationFields($response));
            }
            $body = self::readLimited($response, (int) config('connector-zendesk.http.max_json_bytes', 16_000_000));
            try {
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \RuntimeException('Zendesk returned invalid JSON.');
            }
            if (! is_array($data)) {
                throw new \RuntimeException('Zendesk returned an unexpected response.');
            }

            return $data;
        }
        throw new \LogicException('Unreachable Zendesk response.');
    }

    /**
     * Download an attachment, following at most three HTTPS redirects.
     *
     * Credentials travel only to the account's own host: a `content_url` on
     * the subdomain may require them when "private attachments" is enabled,
     * whereas the CDN it redirects to must never see them. Every hop is
     * re-validated against the attachment host allow-list and DNS-pinned.
     */
    public function download(string $url, int $maxBytes): string
    {
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $options = UrlPolicy::options($url, true) + ['allow_redirects' => false, 'stream' => true];
            $this->countRequest();
            $request = Http::connectTimeout(5)->timeout(120)->withOptions($options);
            if (UrlPolicy::isAccountHost($url, $this->subdomain)) {
                $request = $this->authenticated($request);
            }
            try {
                $response = $request->get($url);
            } catch (ConnectionException) {
                throw new \RuntimeException('Zendesk attachment download failed.');
            }
            if ($response->redirect()) {
                $next = (string) $response->header('Location');
                if (! str_starts_with($next, 'https://')) {
                    throw new \RuntimeException('Zendesk attachment redirect is invalid.');
                }
                $url = $next;

                continue;
            }
            if (! $response->successful()) {
                throw new ZendeskApiException($response->status(), self::retryAfter($response));
            }

            return self::readLimited($response, $maxBytes);
        }
        throw new \RuntimeException('Zendesk attachment exceeded the redirect limit.');
    }

    private function authenticated(PendingRequest $request): PendingRequest
    {
        if ($this->authMode === self::AUTH_OAUTH_TOKEN) {
            return $request->withToken($this->secret);
        }

        return $request->withBasicAuth($this->email.'/token', $this->secret);
    }

    private function countRequest(): void
    {
        if ($this->beforeRequest !== null) {
            ($this->beforeRequest)();
        }
        $this->physicalRequests++;
    }

    private static function retryAfter(Response $response): int
    {
        $seconds = (int) $response->header('Retry-After');
        if ($seconds > 0) {
            return $seconds;
        }
        // Some endpoints answer with a RateLimit-Reset / ratelimit-reset header instead.
        $reset = (int) ($response->header('RateLimit-Reset') ?: $response->header('ratelimit-reset'));

        return $reset > 0 ? $reset : 60;
    }

    /**
     * Zendesk 400/422 bodies look like `{"error":"RecordInvalid","description":"…","details":{"field":[{"description":"…","error":"…"}]}}`.
     * Field names and error codes are allow-listed; descriptions are dropped because they echo input.
     *
     * @return list<array{field:string,code:string}>
     */
    private static function validationFields(Response $response): array
    {
        if (! in_array($response->status(), [400, 422], true)) {
            return [];
        }
        $fields = [];
        try {
            $error = json_decode(self::readLimited($response, 16384), true, 32, JSON_THROW_ON_ERROR);
            foreach (array_slice((array) ($error['details'] ?? []), 0, 10, true) as $field => $problems) {
                if (! is_string($field) || preg_match('/^[a-z][a-z0-9_.\[\]]{0,63}$/D', $field) !== 1) {
                    continue;
                }
                $code = is_array($problems) ? (string) (($problems[0]['error'] ?? '') ?: '') : '';
                $fields[] = ['field' => $field, 'code' => preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $code) === 1 ? $code : 'invalid'];
            }
        } catch (\Throwable) {
            // Diagnostic bodies cannot replace the original HTTP failure.
        }

        return $fields;
    }

    public static function readLimited(Response $response, int $maxBytes): string
    {
        if ((int) $response->header('Content-Length') > $maxBytes) {
            throw new \RuntimeException('Zendesk response exceeds the configured size limit.');
        }
        $stream = $response->toPsrResponse()->getBody();
        $bytes = '';
        while (! $stream->eof()) {
            $bytes .= $stream->read(min(65536, $maxBytes - strlen($bytes) + 1));
            if (strlen($bytes) > $maxBytes) {
                throw new \RuntimeException('Zendesk response exceeds the configured size limit.');
            }
        }

        return $bytes;
    }
}
