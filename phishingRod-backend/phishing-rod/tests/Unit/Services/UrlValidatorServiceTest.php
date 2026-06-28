<?php

namespace Tests\Unit\Services;

use App\Services\Security\UrlValidatorService;
use PHPUnit\Framework\TestCase;

class UrlValidatorServiceTest extends TestCase
{
    private function validator(): UrlValidatorService
    {
        return new UrlValidatorService();
    }

    // --- Phase 2: scheme / basic acceptance ---

    public function test_accepts_normal_http_url(): void
    {
        $this->assertTrue($this->validator()->passes('http://example.com'));
    }

    public function test_accepts_normal_https_url(): void
    {
        $this->assertTrue($this->validator()->passes('https://example.com/path?q=1'));
    }

    public function test_rejects_non_url_text(): void
    {
        $this->assertFalse($this->validator()->passes('not a url'));
    }

    public function test_rejects_file_scheme(): void
    {
        $this->assertFalse($this->validator()->passes('file:///etc/passwd'));
    }

    public function test_rejects_javascript_scheme(): void
    {
        $this->assertFalse($this->validator()->passes('javascript:alert(1)'));
    }

    public function test_rejects_data_scheme(): void
    {
        $this->assertFalse($this->validator()->passes('data:text/html,<script>alert(1)</script>'));
    }

    public function test_rejects_ftp_scheme(): void
    {
        $this->assertFalse($this->validator()->passes('ftp://example.com/file'));
    }

    public function test_rejects_gopher_scheme(): void
    {
        $this->assertFalse($this->validator()->passes('gopher://example.com'));
    }

    // --- Phase 13: SSRF / internal-address protection ---

    public function test_rejects_localhost(): void
    {
        $this->assertFalse($this->validator()->passes('http://localhost'));
    }

    public function test_rejects_loopback_ipv4(): void
    {
        $this->assertFalse($this->validator()->passes('http://127.0.0.1'));
    }

    public function test_rejects_zero_address(): void
    {
        $this->assertFalse($this->validator()->passes('http://0.0.0.0'));
    }

    public function test_rejects_ipv6_loopback(): void
    {
        $this->assertFalse($this->validator()->passes('http://[::1]'));
    }

    public function test_rejects_private_10_range(): void
    {
        $this->assertFalse($this->validator()->passes('http://10.0.0.5'));
    }

    public function test_rejects_private_172_range(): void
    {
        $this->assertFalse($this->validator()->passes('http://172.16.0.1'));
    }

    public function test_rejects_private_192_168_range(): void
    {
        $this->assertFalse($this->validator()->passes('http://192.168.1.1'));
    }

    public function test_rejects_link_local_range(): void
    {
        $this->assertFalse($this->validator()->passes('http://169.254.169.254'));
    }

    public function test_rejects_overly_long_url(): void
    {
        $longUrl = 'https://example.com/' . str_repeat('a', 3000);

        $this->assertFalse($this->validator()->passes($longUrl));
    }

    public function test_accepts_normal_public_https_url(): void
    {
        $this->assertTrue($this->validator()->passes('https://www.google.com/search?q=test'));
    }
}
