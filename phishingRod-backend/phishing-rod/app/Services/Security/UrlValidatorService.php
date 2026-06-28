<?php

namespace App\Services\Security;

/**
 * Validates submitted URLs before they enter the scan pipeline.
 *
 * This is the single source of truth for "is this URL safe to accept".
 * It guards against unsupported/dangerous schemes and SSRF attempts that
 * target internal, loopback, private, or otherwise reserved addresses.
 *
 * The same instance is used by StoreScanRequest (for HTTP 422 messages)
 * and can be reused by queue jobs / services later in the pipeline.
 */
class UrlValidatorService
{
    /**
     * Only these URL schemes may ever be scanned.
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Host names that must always be rejected regardless of DNS.
     */
    private const BLOCKED_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
    ];

    private const MAX_LENGTH = 2048;

    /**
     * Convenience boolean check.
     */
    public function passes(string $url): bool
    {
        return $this->validate($url) === null;
    }

    /**
     * Validate the URL.
     *
     * @return string|null A human-readable error message, or null when the URL is acceptable.
     */
    public function validate(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return 'A URL is required.';
        }

        if (mb_strlen($url) > self::MAX_LENGTH) {
            return 'The URL is too long.';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme === null || $scheme === false) {
            return 'The value is not a valid URL.';
        }

        if (! in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            return 'The URL must use the http or https scheme.';
        }

        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === false || $host === '') {
            return 'The URL host could not be parsed.';
        }

        // IPv6 hosts arrive bracketed (e.g. "[::1]"); strip for inspection.
        $host = strtolower(trim($host, '[]'));

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'The URL must not target internal or local addresses.';
        }

        if ($this->isBlockedIp($host)) {
            return 'The URL must not target private, loopback, or reserved IP addresses.';
        }

        return null;
    }

    /**
     * Reject literal IP hosts that fall inside private, loopback,
     * link-local, or otherwise reserved ranges (SSRF protection).
     */
    private function isBlockedIp(string $host): bool
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP);

        if ($ip === false) {
            // Not a literal IP — treated as a domain, allowed here.
            return false;
        }

        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $isPublic === false;
    }
}
