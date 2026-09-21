<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FoundationPageTest extends TestCase
{
    public function test_foundation_page_renders_database_status(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->with('SELECT 1 AS connected')
            ->andReturn((object) ['connected' => 1]);

        config()->set('app.url', 'http://localhost');
        URL::forceRootUrl('http://localhost');

        $response = $this->get('/_foundation');

        $response->assertOk()
            ->assertSee('MySQL connection')
            ->assertSee('Application asset');
    }

    public function test_configured_urls_keep_the_base_path(): void
    {
        URL::forceRootUrl('http://localhost:8080/cabinet');

        $this->assertSame(
            'http://localhost:8080/cabinet/redirect-check',
            route('foundation.redirect'),
        );
        $this->assertSame('http://localhost:8080/cabinet/app.css', asset('app.css'));
    }
}
