<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Http;

/**
 * Every URL the connector talks to passes through here.
 *
 * Two rules, applied before any socket is opened:
 *   1. the API host is exactly `{subdomain}.zendesk.com` over HTTPS on 443,
 *      with no userinfo, fragment or custom port — the only place where
 *      credentials are ever sent;
 *   2. an attachment URL (and every redirect hop that follows it) must match
 *      the `attachments.allowed_hosts` patterns and resolve to a public
 *      address, which is then pinned for the actual request so a DNS answer
 *      cannot change between the check and the connect.
 */
final class UrlPolicy
{
    /**
     * Normalise operator input ("company", "company.zendesk.com",
     * "https://company.zendesk.com/") to the bare subdomain.
     */
    public static function subdomain(string $input): string
    {
        $input = strtolower(trim($input));
        if (str_starts_with($input, 'https://')) {
            $input = substr($input, 8);
        }
        $input = rtrim($input, '/');
        if (str_ends_with($input, '.zendesk.com')) {
            $input = substr($input, 0, -strlen('.zendesk.com'));
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $input) !== 1) {
            throw new \InvalidArgumentException('Enter the account subdomain, for example company or company.zendesk.com.');
        }

        return $input;
    }

    public static function host(string $subdomain): string
    {
        return self::subdomain($subdomain).'.zendesk.com';
    }

    /**
     * Whether a URL points at the account's own host: the only host that may
     * receive the Authorization header on an attachment download.
     */
    public static function isAccountHost(string $url, string $subdomain): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST)) === self::host($subdomain);
    }

    /**
     * Validate a URL and return the Guzzle options that pin its resolved
     * public address.
     *
     * @return array<string|int,mixed>
     */
    public static function options(string $url, bool $download = false): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \InvalidArgumentException('Zendesk URLs must use HTTPS without credentials, fragments or custom ports.');
        }
        if ($download) {
            self::assertAllowedAttachmentHost($host);
        }
        if (! $download && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.zendesk\.com$/D', $host) !== 1) {
            throw new \InvalidArgumentException('Zendesk API host is not an account subdomain.');
        }
        if (! config('connector-zendesk.http.resolve_dns', true)) {
            return [];
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($ip)) {
                continue;
            }
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('Zendesk URL resolved to a non-public address.');
            }
            $addresses[] = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        }
        if ($addresses === []) {
            throw new \RuntimeException('Zendesk host could not be resolved.');
        }

        return ['curl' => [CURLOPT_RESOLVE => [$host.':443:'.implode(',', $addresses)]]];
    }

    private static function assertAllowedAttachmentHost(string $host): void
    {
        foreach ((array) config('connector-zendesk.attachments.allowed_hosts', []) as $pattern) {
            if (is_string($pattern) && fnmatch($pattern, $host, FNM_CASEFOLD)) {
                return;
            }
        }
        throw new \InvalidArgumentException('Zendesk attachment host is not allowed.');
    }
}
