<?php

namespace App\Actions\Scans;

/**
 * Lightly normalizes a submitted URL.
 *
 * Intentionally conservative for now: it only trims whitespace, drops a
 * trailing slash, lowercases the host, and extracts the domain. It does NOT
 * touch query parameters or paths so that we don't accidentally change the
 * resource being scanned.
 */
class NormalizeUrlAction
{
    /**
     * @return array{submitted_url: string, normalized_url: string, domain: string|null}
     */
    public function execute(string $url): array
    {
        $submittedUrl = trim($url);

        $normalizedUrl = rtrim($submittedUrl, '/');

        $host = parse_url($normalizedUrl, PHP_URL_HOST);
        $domain = null;

        if (is_string($host) && $host !== '') {
            $domain = strtolower($host);

            // Lowercase only the host component, leaving the path/query untouched.
            $position = stripos($normalizedUrl, $host);

            if ($position !== false) {
                $normalizedUrl = substr_replace($normalizedUrl, $domain, $position, strlen($host));
            }
        }

        return [
            'submitted_url'  => $submittedUrl,
            'normalized_url' => $normalizedUrl,
            'domain'         => $domain,
        ];
    }
}
