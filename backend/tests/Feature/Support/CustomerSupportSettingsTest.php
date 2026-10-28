<?php

namespace Tests\Feature\Support;

use App\Domain\Settings\PlatformSettingsService;
use App\Livewire\Admin\CustomerSupportSettings;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerSupportSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function staffWith(array $permissions): StaffUser
    {
        $preset = PermissionPreset::create(['name' => 'p-'.uniqid()]);
        foreach ($permissions as $key) {
            $preset->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'Test']));
        }

        return StaffUser::factory()->create(['is_super_admin' => false, 'permission_preset_id' => $preset->id]);
    }

    public function test_an_admin_saves_the_link_and_the_app_config_returns_it(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);

        Livewire::actingAs($admin, 'staff')
            ->test(CustomerSupportSettings::class)
            ->set('supportUrl', 'https://help.minimax.example/support')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('savedUrl', 'https://help.minimax.example/support');

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_settings.updated', 'actor_id' => $admin->id]);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/app-config')
            ->assertOk()
            ->assertJsonPath('data.support_url', 'https://help.minimax.example/support');
    }

    public function test_the_link_persists_across_a_reload(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        app(PlatformSettingsService::class)->set('support_url', 'mailto:help@minimax.example', $admin);

        $this->assertDatabaseHas('platform_settings', ['key' => 'support_url']);

        // What every new request does at boot: forget the in-memory value, re-read the table.
        config(['minimax.support_url' => null]);
        $provider = $this->app->getProvider(AppServiceProvider::class);
        (new \ReflectionMethod($provider, 'overrideConfigFromPlatformSettings'))->invoke($provider);

        $this->assertSame('mailto:help@minimax.example', config('minimax.support_url'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeLinks(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<b>x</b>'],
            'plain http' => ['http://help.example.com'],
            'intent' => ['intent://scan/#Intent;scheme=zxing;end'],
            'file' => ['file:///etc/passwd'],
            'no scheme' => ['help.example.com'],
            'bad mailto' => ['mailto:not-an-email'],
            'spaces' => ['https://help.example.com/a b'],
        ];
    }

    #[DataProvider('unsafeLinks')]
    public function test_unsafe_or_malformed_links_are_refused_server_side(string $link): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);

        Livewire::actingAs($admin, 'staff')
            ->test(CustomerSupportSettings::class)
            ->set('supportUrl', $link)
            ->call('save')
            ->assertHasErrors('supportUrl');

        $this->assertDatabaseMissing('platform_settings', ['key' => 'support_url']);
    }

    public function test_an_empty_link_turns_the_row_off(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        app(PlatformSettingsService::class)->set('support_url', 'https://help.example.com', $admin);

        Livewire::actingAs($admin, 'staff')
            ->test(CustomerSupportSettings::class)
            ->set('supportUrl', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('savedUrl', null);
    }

    public function test_staff_without_settings_manage_cannot_open_or_save(): void
    {
        $staff = $this->staffWith(['support.manage', 'users.view']);

        $this->actingAs($staff, 'staff')->get(route('staff.customer-support'))->assertForbidden();

        Livewire::actingAs($staff, 'staff')
            ->test(CustomerSupportSettings::class)
            ->set('supportUrl', 'https://evil.example.com')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('platform_settings', ['key' => 'support_url']);
    }

    public function test_the_app_config_needs_a_signed_in_player(): void
    {
        $this->getJson('/api/v1/app-config')->assertUnauthorized();
    }
}
