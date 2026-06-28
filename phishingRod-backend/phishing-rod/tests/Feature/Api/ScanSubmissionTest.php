<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_url_creates_scan(): void
    {
        $response = $this->postJson('/api/scans', [
            'url' => 'https://example.com',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'queued');
        $response->assertJsonPath('data.submitted_url', 'https://example.com');

        $this->assertDatabaseHas('scans', [
            'submitted_url' => 'https://example.com',
            'status'        => 'queued',
        ]);
    }

    public function test_created_scan_has_uuid_and_does_not_expose_internal_id(): void
    {
        $response = $this->postJson('/api/scans', [
            'url' => 'https://example.com',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.uuid', fn ($uuid) => is_string($uuid) && $uuid !== '');
        $response->assertJsonMissingPath('data.id');
    }

    public function test_normalized_url_and_domain_are_stored(): void
    {
        $response = $this->postJson('/api/scans', [
            'url' => 'https://EXAMPLE.com/',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.normalized_url', 'https://example.com');
        $response->assertJsonPath('data.domain', 'example.com');
    }

    public function test_missing_url_returns_validation_error(): void
    {
        $response = $this->postJson('/api/scans', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    public function test_unsupported_scheme_returns_validation_error(): void
    {
        $response = $this->postJson('/api/scans', [
            'url' => 'ftp://example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    public function test_internal_address_is_rejected(): void
    {
        $response = $this->postJson('/api/scans', [
            'url' => 'http://127.0.0.1',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }
}
