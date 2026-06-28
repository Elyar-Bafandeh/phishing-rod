<?php

namespace Tests\Unit\Actions;

use App\Actions\Scans\NormalizeUrlAction;
use PHPUnit\Framework\TestCase;

class NormalizeUrlActionTest extends TestCase
{
    private function action(): NormalizeUrlAction
    {
        return new NormalizeUrlAction();
    }

    public function test_trims_whitespace(): void
    {
        $result = $this->action()->execute('   https://example.com   ');

        $this->assertSame('https://example.com', $result['normalized_url']);
    }

    public function test_removes_trailing_slash(): void
    {
        $result = $this->action()->execute('https://example.com/');

        $this->assertSame('https://example.com', $result['normalized_url']);
    }

    public function test_extracts_domain_from_https_url(): void
    {
        $result = $this->action()->execute('https://example.com/path');

        $this->assertSame('example.com', $result['domain']);
    }

    public function test_extracts_domain_from_http_url(): void
    {
        $result = $this->action()->execute('http://example.org/path');

        $this->assertSame('example.org', $result['domain']);
    }

    public function test_lowercases_only_the_host(): void
    {
        $result = $this->action()->execute('https://EXAMPLE.com/MixedCasePath');

        $this->assertSame('https://example.com/MixedCasePath', $result['normalized_url']);
        $this->assertSame('example.com', $result['domain']);
    }

    public function test_preserves_query_string(): void
    {
        $result = $this->action()->execute('https://example.com/search?q=Hello+World&page=2');

        $this->assertSame(
            'https://example.com/search?q=Hello+World&page=2',
            $result['normalized_url']
        );
    }

    public function test_does_not_over_normalize_url_unexpectedly(): void
    {
        $url = 'https://example.com/a/b/c?x=1#frag';

        $result = $this->action()->execute($url);

        // Path, query and fragment must remain untouched.
        $this->assertSame($url, $result['normalized_url']);
    }
}
