<?php

namespace Tests\Feature;

use App\Support\PrototypeCatalog;
use App\Support\PrototypeFixtures;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PrototypeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'http://localhost');
        URL::forceRootUrl('http://localhost');
    }

    public function test_catalog_and_every_documented_variant_render_without_database_access(): void
    {
        $this->withoutExceptionHandling();
        DB::purge();
        config()->set('database.default', 'missing-prototype-database');
        $catalog = $this->get('http://localhost/_prototype')->assertOk();
        $this->assertCount(31, PrototypeCatalog::pages());
        foreach (PrototypeCatalog::pages() as $slug => $page) {
            foreach ($page['variants'] as $variant) {
                $path = '/_prototype/'.$slug.'/'.$variant;
                $catalog->assertSee($path);
                $expectedView = $slug === 'errors' ? 'errors.'.$variant : $page['view'];
                if ($variant === 'permission') {
                    $expectedView = 'errors.403';
                }
                $this->get('http://localhost'.$path)->assertOk()->assertViewIs($expectedView);
            }
        }
    }

    public function test_unknown_variants_are_not_rendered(): void
    {
        $this->get('http://localhost/_prototype/group/not-a-status')->assertNotFound();
        $this->post('http://localhost/_prototype/group/draft')->assertStatus(405);
    }

    public function test_production_boot_does_not_register_prototype_routes(): void
    {
        $original = [getenv('APP_ENV'), $_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null];
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
        try {
            $app = require base_path('bootstrap/app.php');
            $app->make(Kernel::class)->bootstrap();
            foreach ($app['router']->getRoutes() as $route) {
                $this->assertStringNotContainsString('_prototype', $route->uri());
            }
        } finally {
            putenv('APP_ENV='.($original[0] ?: 'testing'));
            $_ENV['APP_ENV'] = $original[1];
            $_SERVER['APP_ENV'] = $original[2];
            $this->refreshApplication();
        }
    }

    public function test_group_statuses_copy_control_payment_wording_and_local_assets(): void
    {
        URL::forceRootUrl('http://localhost:8080/cabinet');
        $response = $this->get('http://localhost/_prototype/admin-group/active')->assertOk();
        $response->assertSee('Интеграция с gruppa.info')->assertSee('ID группы для gruppa.info')
            ->assertSee('data-copy="public_uuid"', false)
            ->assertSee('11111111-2222-4333-8444-555555555555');
        foreach (['vendor/bootstrap/5.3.8/css/bootstrap.min.css', 'ui.css', 'ui.js'] as $asset) {
            $response->assertSee('http://localhost:8080/cabinet/'.$asset, false);
        }
        foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net', '@vite'] as $external) {
            $response->assertDontSee($external);
        }
        $this->get('http://localhost/_prototype/groups/normal')->assertOk()->assertSee('group:awaiting_payment')->assertSee('group:revision')->assertSee('group:expired');
        $this->get('http://localhost/_prototype/payment-result/browser-cancel')->assertOk()->assertSee('Оплата подтверждается WEBPAY')->assertDontSee('Отмена оплаты подтверждена');
        $this->get('http://localhost/_prototype/admin-payment/succeeded')->assertOk()->assertSee('не отправляет деньги');
        $css = file_get_contents(public_path('ui.css'));
        foreach ([500, 600] as $weight) {
            $this->assertStringContainsString('montserrat-'.$weight.'.woff2', $css);
            $this->assertFileExists(public_path('fonts/montserrat/montserrat-'.$weight.'.woff2'));
        }
        $this->assertStringContainsString('font-display:swap', $css);
    }

    public function test_unrefunded_payment_blocks_delete_even_for_a_historically_free_group(): void
    {
        $data = PrototypeFixtures::page('group', 'draft');
        $data['group']['free'] = true;
        $data['group']['has_unrefunded_payment'] = true;
        $html = view('psychologist.groups._actions', $data)->render();
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>Удалить<\/button>/', $html);
    }

    public function test_error_views_can_render_without_prototype_data(): void
    {
        foreach ([403, 404, 419, 429, 500] as $code) {
            $this->assertStringContainsString('Вернуться в кабинет', view('errors.'.$code)->render());
        }
    }
}
