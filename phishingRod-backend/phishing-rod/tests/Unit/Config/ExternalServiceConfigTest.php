<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class ExternalServiceConfigTest extends TestCase
{
    public function test_urlscan_config_exposes_expected_keys(): void
    {
        $this->assertTrue(config()->has('urlscan.base_url'));
        $this->assertTrue(config()->has('urlscan.api_key'));
        $this->assertTrue(config()->has('urlscan.visibility'));
        $this->assertTrue(config()->has('urlscan.timeout'));
    }

    public function test_urlscan_uses_safe_defaults(): void
    {
        $this->assertSame('https://urlscan.io', config('urlscan.base_url'));
        $this->assertSame('unlisted', config('urlscan.visibility'));
        $this->assertIsInt(config('urlscan.timeout'));
        $this->assertSame(30, config('urlscan.timeout'));
    }

    public function test_ml_config_exposes_expected_keys(): void
    {
        $this->assertTrue(config()->has('ml.base_url'));
        $this->assertTrue(config()->has('ml.token'));
        $this->assertTrue(config()->has('ml.timeout'));
        $this->assertTrue(config()->has('ml.active_model'));
    }

    public function test_ml_uses_safe_defaults(): void
    {
        $this->assertSame('http://127.0.0.1:9000', config('ml.base_url'));
        $this->assertIsInt(config('ml.timeout'));
        $this->assertSame(30, config('ml.timeout'));
    }

    public function test_ml_active_model_defaults_to_primary_combined_model(): void
    {
        $this->assertSame('best_combined_model.joblib', config('ml.active_model'));
    }

    public function test_ml_active_model_is_never_the_deprecated_html_model(): void
    {
        $this->assertNotSame('best_html_model.joblib', config('ml.active_model'));
    }
}
