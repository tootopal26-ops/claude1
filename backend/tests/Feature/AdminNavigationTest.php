<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\View\AdminNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function staffWith(string ...$permissionKeys): StaffUser
    {
        $preset = PermissionPreset::create(['name' => 'Preset '.uniqid()]);

        foreach ($permissionKeys as $key) {
            $preset->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => explode('.', $key)[0]]));
        }

        return StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
    }

    /**
     * @return list<string>
     */
    private function visibleLabels(StaffUser $user): array
    {
        return collect(AdminNavigation::forUser($user))->flatMap(fn ($group) => collect($group['items'])->pluck('label'))->all();
    }

    public function test_every_navigation_item_points_at_a_registered_route(): void
    {
        foreach (AdminNavigation::catalog() as $group) {
            foreach ($group['items'] as $item) {
                $this->assertTrue(Route::has($item['route']), "Navigation route \"{$item['route']}\" is not registered");
            }
        }
    }

    public function test_navigation_permissions_match_the_permission_the_route_actually_requires(): void
    {
        foreach (AdminNavigation::catalog() as $group) {
            foreach ($group['items'] as $item) {
                $required = collect(Route::getRoutes()->getByName($item['route'])->gatherMiddleware())
                    ->first(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'permission:'));

                $this->assertSame(
                    $item['permission'] === null ? null : "permission:{$item['permission']}",
                    $required,
                    "\"{$item['label']}\" is shown for a different permission than its route enforces",
                );
            }
        }
    }

    public function test_a_super_admin_sees_every_navigation_item(): void
    {
        $superAdmin = StaffUser::factory()->create(['is_super_admin' => true]);
        $total = collect(AdminNavigation::catalog())->sum(fn ($group) => count($group['items']));

        $this->assertCount($total, $this->visibleLabels($superAdmin));
    }

    public function test_an_account_without_permissions_sees_only_the_dashboard(): void
    {
        $this->assertSame(['Dashboard'], $this->visibleLabels($this->staffWith()));
    }

    public function test_navigation_is_filtered_to_the_accounts_permissions_and_empty_groups_are_dropped(): void
    {
        $user = $this->staffWith('withdrawals.manage', 'legal.manage');

        $this->assertSame(['Dashboard', 'Withdrawals', 'Legal'], $this->visibleLabels($user));
        $this->assertSame(['Overview', 'Users & Finance', 'System'], collect(AdminNavigation::forUser($user))->pluck('label')->all());
    }

    public function test_the_portal_identity_follows_the_role(): void
    {
        $this->assertSame('Admin Console', AdminNavigation::portalLabel(StaffUser::factory()->create(['is_super_admin' => true])));
        $this->assertSame('Staff Portal', AdminNavigation::portalLabel($this->staffWith('legal.manage')));
        $this->assertSame('Super admin', AdminNavigation::roleLabel(StaffUser::factory()->create(['is_super_admin' => true])));
    }

    public function test_the_shell_renders_the_staff_portal_identity_and_only_permitted_links(): void
    {
        $user = $this->staffWith('legal.manage');

        $response = $this->actingAs($user, 'staff')->get(route('staff.home'));

        $response->assertOk()
            ->assertSee('Staff Portal')
            ->assertSee(route('staff.legal.index'), false)
            ->assertDontSee(route('staff.withdrawals.index'), false)
            ->assertDontSee(route('staff.staff.index'), false);
    }
}
