<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionHttpsRedirectTest extends TestCase
{
    public function test_public_rewrite_enforces_https_only_for_the_canonical_host(): void
    {
        $htaccess = file_get_contents(dirname(__DIR__, 2).'/public/.htaccess');
        $this->assertNotFalse($htaccess);

        $rule = <<<'RULE'
    RewriteCond %{HTTP_HOST} ^gruppa\.info(?::80)?$ [NC]
    RewriteCond %{HTTPS} !=on
    RewriteRule ^ https://gruppa.info%{REQUEST_URI} [R=301,L]
RULE;
        $this->assertStringContainsString($rule, $htaccess);
        $this->assertLessThan(
            strpos($htaccess, '# Handle Authorization Header'),
            strpos($htaccess, 'RewriteCond %{HTTP_HOST} ^gruppa\.info'),
        );
        $this->assertStringNotContainsString('X-Forwarded-Proto', $htaccess);
        $this->assertStringNotContainsString('SERVER_PORT', $htaccess);

        foreach (['gruppa.info', 'gruppa.info:80', 'GRUPPA.INFO'] as $host) {
            $this->assertSame(1, preg_match('/^gruppa\.info(?::80)?$/i', $host));
        }
        foreach (['localhost', 'localhost:8080', 'evil.example', 'gruppa.info.evil.example'] as $host) {
            $this->assertSame(0, preg_match('/^gruppa\.info(?::80)?$/i', $host));
        }
    }
}
