<?php

namespace Tests\Unit\Services;

use App\Exceptions\UrlscanException;
use App\Services\Urlscan\UrlscanClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UrlscanClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Use only fake config values — never a real API key in tests.
        config([
            'urlscan.base_url'   => 'https://urlscan.io',
            'urlscan.api_key'    => 'fake-urlscan-key',
            'urlscan.visibility' => 'unlisted',
            'urlscan.timeout'    => 30,
        ]);
    }

    private function client(): UrlscanClient
    {
        return new UrlscanClient();
    }

    public function test_submit_url_posts_to_scan_endpoint_with_expected_payload_and_header(): void
    {
        Http::fake([
            'urlscan.io/api/v1/scan/' => Http::response([
                'uuid' => 'scan-123',
                'api'  => 'https://urlscan.io/api/v1/result/scan-123/',
            ], 200),
        ]);

        $result = $this->client()->submitUrl('https://example.com');

        $this->assertSame('scan-123', $result['uuid']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://urlscan.io/api/v1/scan/'
                && $request->hasHeader('api-key', 'fake-urlscan-key')
                && $request['url'] === 'https://example.com'
                && $request['visibility'] === 'unlisted'
                && $request['tags'] === ['phishing-rod', 'thesis-demo'];
        });
    }

    public function test_get_result_fetches_result_endpoint_and_returns_array(): void
    {
        Http::fake([
            'urlscan.io/api/v1/result/*' => Http::response([
                'page' => ['url' => 'https://example.com'],
            ], 200),
        ]);

        $result = $this->client()->getResult('scan-123');

        $this->assertSame('https://example.com', $result['page']['url']);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://urlscan.io/api/v1/result/scan-123/');
    }

    public function test_get_dom_returns_raw_html_string(): void
    {
        Http::fake([
            'urlscan.io/dom/*' => Http::response('<html><body>hello</body></html>', 200),
        ]);

        $dom = $this->client()->getDom('scan-123');

        $this->assertSame('<html><body>hello</body></html>', $dom);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://urlscan.io/dom/scan-123/');
    }

    public function test_missing_api_key_throws_before_any_request_is_sent(): void
    {
        config(['urlscan.api_key' => null]);
        Http::fake();

        try {
            $this->client()->submitUrl('https://example.com');
            $this->fail('Expected UrlscanException was not thrown.');
        } catch (UrlscanException $e) {
            $this->assertStringContainsString('API key', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_rate_limit_is_translated_to_controlled_exception(): void
    {
        Http::fake(['urlscan.io/*' => Http::response('rate limited', 429)]);

        $this->expectException(UrlscanException::class);
        $this->expectExceptionCode(429);

        $this->client()->submitUrl('https://example.com');
    }

    public function test_unauthorized_is_translated_to_controlled_exception(): void
    {
        Http::fake(['urlscan.io/*' => Http::response('unauthorized', 401)]);

        $this->expectException(UrlscanException::class);
        $this->expectExceptionCode(401);

        $this->client()->submitUrl('https://example.com');
    }

    public function test_not_found_result_is_translated_to_controlled_exception(): void
    {
        Http::fake(['urlscan.io/*' => Http::response('not found', 404)]);

        $this->expectException(UrlscanException::class);
        $this->expectExceptionCode(404);

        $this->client()->getResult('missing-scan');
    }

    public function test_does_not_leak_api_key_in_exception_message(): void
    {
        Http::fake(['urlscan.io/*' => Http::response('boom', 500)]);

        try {
            $this->client()->submitUrl('https://example.com');
            $this->fail('Expected UrlscanException was not thrown.');
        } catch (UrlscanException $e) {
            $this->assertStringNotContainsString('fake-urlscan-key', $e->getMessage());
        }
    }
}
