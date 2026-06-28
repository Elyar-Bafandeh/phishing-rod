<?php

namespace App\Services\Urlscan;

use App\Exceptions\UrlscanException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Single point of contact for all urlscan.io HTTP communication.
 *
 * The application never browses submitted URLs itself — urlscan.io loads the
 * page in a sandbox and we retrieve the captured result/DOM. All returned
 * content must be treated as untrusted (stored as text, never rendered).
 *
 * Configuration (base URL, API key, visibility, timeout) comes from
 * config/urlscan.php so credentials stay out of the codebase.
 */
class UrlscanClient
{
    /**
     * Tags attached to every submission for easy filtering in urlscan.io.
     */
    private const TAGS = ['phishing-rod', 'thesis-demo'];

    /**
     * Submit a URL for scanning.
     *
     * @return array<string, mixed> Decoded response (includes uuid, api result URL, etc.)
     *
     * @throws UrlscanException
     */
    public function submitUrl(string $url): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request
            ->acceptJson()
            ->post('/api/v1/scan/', [
                'url'        => $url,
                'visibility' => config('urlscan.visibility', 'unlisted'),
                'tags'       => self::TAGS,
            ]));

        return $response->json() ?? [];
    }

    /**
     * Fetch the full scan result JSON for a previously submitted scan.
     *
     * @return array<string, mixed>
     *
     * @throws UrlscanException
     */
    public function getResult(string $scanId): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request
            ->acceptJson()
            ->get("/api/v1/result/{$scanId}/"));

        return $response->json() ?? [];
    }

    /**
     * Fetch the rendered DOM snapshot as raw, untrusted HTML text.
     *
     * @throws UrlscanException
     */
    public function getDom(string $scanId): string
    {
        $response = $this->send(fn (PendingRequest $request) => $request
            ->get("/dom/{$scanId}/"));

        return $response->body();
    }

    /**
     * Build a pre-configured HTTP request bound to urlscan.io.
     *
     * @throws UrlscanException When the API key is not configured.
     */
    private function pendingRequest(): PendingRequest
    {
        $apiKey = config('urlscan.api_key');

        if (blank($apiKey)) {
            throw UrlscanException::missingApiKey();
        }

        return Http::baseUrl(rtrim((string) config('urlscan.base_url'), '/'))
            ->withHeaders(['api-key' => $apiKey])
            ->timeout((int) config('urlscan.timeout', 30));
    }

    /**
     * Execute a request and translate transport/HTTP failures into a
     * controlled UrlscanException.
     *
     * @param  callable(PendingRequest): Response  $callback
     *
     * @throws UrlscanException
     */
    private function send(callable $callback): Response
    {
        try {
            $response = $callback($this->pendingRequest());
        } catch (ConnectionException $e) {
            throw UrlscanException::connectionFailed($e);
        }

        $this->guardResponse($response);

        return $response;
    }

    /**
     * Map non-successful HTTP responses to meaningful exceptions.
     *
     * @throws UrlscanException
     */
    private function guardResponse(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw match ($response->status()) {
            401, 403 => UrlscanException::unauthorized(),
            404      => UrlscanException::notFound(),
            429      => UrlscanException::rateLimited(),
            default  => UrlscanException::requestFailed($response->status()),
        };
    }
}
