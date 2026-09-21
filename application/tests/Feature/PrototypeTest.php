<?php

namespace Tests\Feature;

use App\Support\PrototypeCatalog;
use App\Support\PrototypeFixtures;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;
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
        $this->assertSame(249, array_sum(array_map(fn ($page) => count($page['variants']), PrototypeCatalog::pages())));
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

    public function test_prototype_login_and_navigation_remain_no_op(): void
    {
        $this->get('http://localhost/_prototype/login/normal')->assertOk()
            ->assertSee('data-prototype-form')->assertDontSee('name="_token"', false)
            ->assertDontSee('type="submit"', false);
        foreach (['groups/normal', 'admin-home/normal'] as $page) {
            $this->get('http://localhost/_prototype/'.$page)->assertOk()
                ->assertSee('data-noop')->assertDontSee('action="'.route('logout').'"', false);
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
                $this->assertStringNotContainsString('_foundation', $route->uri());
                $this->assertNotSame('redirect-check', $route->uri());
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

    public function test_shared_controls_and_notices_use_the_revised_design_constraints(): void
    {
        $css = file_get_contents(public_path('ui.css'));
        preg_match('/--control-radius:\s*(\d+)px/', $css, $radius);
        $this->assertNotEmpty($radius);
        $this->assertGreaterThanOrEqual(8, (int) $radius[1]);
        $this->assertLessThanOrEqual(10, (int) $radius[1]);
        $this->assertMatchesRegularExpression('/\.form-control,\.form-select\s*\{[^}]*border-radius:var\(--control-radius\)/s', $css);
        $this->assertDoesNotMatchRegularExpression('/(?:form-control|form-select)[^{]*\{[^}]*border-radius:var\(--pill\)/s', $css);
        foreach (['title' => 38, 'section' => 24, 'subsection' => 18] as $token => $size) {
            $this->assertStringContainsString('--'.$token.':'.$size.'px', $css);
        }
        $this->assertStringContainsString('--title:30px', $css);
        $this->assertDoesNotMatchRegularExpression('/(?:https?:)?\/\//', $css);

        $form = $this->get('http://localhost/_prototype/group-form/validation')->assertOk();
        $form->assertSee('form-control', false)->assertSee('form-select', false)
            ->assertSee('is-invalid', false);
        $html = Blade::render('<x-alert tone="warning" title="Notice">Body</x-alert>');
        $this->assertStringContainsString('<strong class="notice-title">Notice</strong>', $html);
        $this->assertDoesNotMatchRegularExpression('/<h[1-6]\b/', $html);
        $this->get('http://localhost/_prototype/payment-pending/pending')->assertOk()
            ->assertSee('<strong class="notice-title">Оплата подтверждается WEBPAY</strong>', false);
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
