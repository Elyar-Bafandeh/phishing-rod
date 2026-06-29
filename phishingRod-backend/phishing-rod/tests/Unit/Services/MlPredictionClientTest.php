<?php

namespace Tests\Unit\Services;

use App\Exceptions\MlPredictionException;
use App\Services\Ml\MlPredictionClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MlPredictionClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fake config only — never a real token in tests.
        config([
            'ml.base_url' => 'http://127.0.0.1:9000',
            'ml.token'    => 'fake-ml-token',
            'ml.timeout'  => 30,
        ]);
    }

    private function client(): MlPredictionClient
    {
        return new MlPredictionClient();
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeResponseBody(): array
    {
        return [
            'verdict'                       => 'safe',
            'confidence'                    => 0.86,
            'combined_phishing_probability' => 0.14,
            'url_only_fallback'             => false,
            'weights'                       => ['url' => 0.475, 'html' => 0.525],
            'url'                           => [
                'model_name'           => 'best_url_model.joblib',
                'schema_version'       => 'url-v1',
                'label'                => 'safe',
                'phishing_probability' => 0.18,
                'safe_probability'     => 0.82,
            ],
            'html' => null,
            'features' => ['url' => [], 'html' => null],
        ];
    }

    public function test_posts_payload_with_bearer_token_and_returns_decoded_body(): void
    {
        Http::fake([
            '127.0.0.1:9000/predict' => Http::response($this->fakeResponseBody(), 200),
        ]);

        $result = $this->client()->predict([
            'url'            => 'https://example.com',
            'dom_html'       => '<html></html>',
            'urlscan_result' => ['page' => []],
        ]);

        $this->assertSame('safe', $result['verdict']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'http://127.0.0.1:9000/predict'
                && $request->hasHeader('Authorization', 'Bearer fake-ml-token')
                && $request['url'] === 'https://example.com'
                // The two-model service selects no model: payload must omit model_name.
                && ! array_key_exists('model_name', $request->data());
        });
    }

    public function test_missing_config_throws_before_any_request(): void
    {
        config(['ml.token' => null]);
        Http::fake();

        try {
            $this->client()->predict(['url' => 'https://example.com']);
            $this->fail('Expected MlPredictionException was not thrown.');
        } catch (MlPredictionException $e) {
            $this->assertFalse($e->isTransient());
        }

        Http::assertNothingSent();
    }

    public function test_server_error_is_transient(): void
    {
        Http::fake(['127.0.0.1:9000/*' => Http::response('boom', 503)]);

        try {
            $this->client()->predict(['url' => 'https://example.com']);
            $this->fail('Expected MlPredictionException was not thrown.');
        } catch (MlPredictionException $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertTrue($e->isTransient());
        }
    }

    public function test_client_error_is_not_transient(): void
    {
        Http::fake(['127.0.0.1:9000/*' => Http::response('bad request', 422)]);

        try {
            $this->client()->predict(['url' => 'https://example.com']);
            $this->fail('Expected MlPredictionException was not thrown.');
        } catch (MlPredictionException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertFalse($e->isTransient());
        }
    }

    public function test_malformed_response_is_rejected(): void
    {
        Http::fake(['127.0.0.1:9000/*' => Http::response(['unexpected' => true], 200)]);

        $this->expectException(MlPredictionException::class);

        $this->client()->predict(['url' => 'https://example.com']);
    }

    public function test_does_not_leak_token_in_exception_message(): void
    {
        Http::fake(['127.0.0.1:9000/*' => Http::response('boom', 500)]);

        try {
            $this->client()->predict(['url' => 'https://example.com']);
            $this->fail('Expected MlPredictionException was not thrown.');
        } catch (MlPredictionException $e) {
            $this->assertStringNotContainsString('fake-ml-token', $e->getMessage());
        }
    }
}
