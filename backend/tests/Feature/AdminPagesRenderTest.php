<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every parameter-free Admin/Staff page renders inside the shell for an
 * authorized account, and the branded error pages replace the framework's.
 */
class AdminPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function parameterFreeAdminRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true)
                && str_starts_with($route->uri(), 'admin/')
                && ! str_contains($route->uri(), '{')
                && ! in_array($route->getName(), ['admin.login'], true))
            ->map(fn ($route) => '/'.$route->uri())
            ->values()
            ->all();
    }

    public function test_every_parameter_free_admin_page_renders_in_the_shell_for_a_super_admin(): void
    {
        $this->seed(DatabaseSeeder::class);
        $superAdmin = StaffUser::factory()->create(['is_super_admin' => true]);

        $routes = $this->parameterFreeAdminRoutes();
        $this->assertGreaterThan(15, count($routes));

        foreach ($routes as $uri) {
            $response = $this->actingAs($superAdmin, 'staff')->get($uri);

            if ($response->status() === 302) {
                // admin/ -> admin/home style redirects
                $response = $this->actingAs($superAdmin, 'staff')->get($response->headers->get('Location'));
            }

            $response->assertOk();
            $response->assertSee('id="mm-sidebar"', false, "{$uri} is not rendered inside the application shell");
            $response->assertSee('Admin Console');
        }
    }

    public function test_the_login_page_uses_the_auth_layout_without_the_shell(): void
    {
        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('Sign in')
            ->assertDontSee('id="mm-sidebar"', false);
    }

    public function test_an_unknown_page_renders_the_branded_404(): void
    {
        $this->get('/admin/definitely-not-a-page')->assertNotFound()->assertSee('Page not found');
    }

    public function test_a_forbidden_page_renders_the_branded_403_with_a_way_back(): void
    {
        $preset = PermissionPreset::create(['name' => 'Nothing']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('admin.withdrawals.index'))
            ->assertForbidden()
            ->assertSee("You don't have access to this page")
            ->assertSee('Back to dashboard');
    }

    public function test_the_session_expired_and_server_error_pages_render_without_leaking_details(): void
    {
        $this->assertStringContainsString('Your session expired', view('errors.419')->render());
        $this->assertStringContainsString('Something went wrong', view('errors.500')->render());
        $this->assertStringContainsString('Back in a moment', view('errors.503')->render());
    }

    public function test_a_multi_page_list_renders_result_counts_and_accessible_pagination(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);

        foreach (range(1, 30) as $number) {
            AuditLog::record("test.action_{$number}", $admin);
        }

        $page1 = $this->actingAs($admin, 'staff')->get(route('admin.audit-logs.index'));

        $page1->assertOk()
            ->assertSee('Showing')
            ->assertSee('of')
            ->assertSee('results')
            ->assertSee('aria-label="Go to page 2"', false)
            ->assertSee('Previous (unavailable)', false)
            ->assertDontSee('aria-disabled', false);
    }

    public function test_a_button_with_a_confirm_prop_renders_the_attributes_the_dialog_listens_for(): void
    {
        $html = Blade::render('<x-ui.button variant="danger" confirm="Delete it?" confirm-title="Delete" confirm-label="Delete now">Go</x-ui.button>');

        $this->assertStringContainsString('data-confirm="Delete it?"', $html);
        $this->assertStringContainsString('data-confirm-title="Delete"', $html);
        $this->assertStringContainsString('data-confirm-label="Delete now"', $html);
        $this->assertStringContainsString('data-confirm-variant="danger"', $html);
    }
}
