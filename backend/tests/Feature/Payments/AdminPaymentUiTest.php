<?php

namespace Tests\Feature\Payments;

use App\Livewire\Admin\PaymentSettings;
use App\Models\PaymentProviderConfig;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPaymentUiTest extends TestCase
{
    use RefreshDatabase;

    private function grantPreset(array $keys): PermissionPreset
    {
        $preset = PermissionPreset::create(['name' => implode('+', $keys).'-'.uniqid()]);

        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'Test']);
            $preset->permissions()->attach($permission);
        }

        return $preset;
    }

    public function test_a_staff_user_without_payments_view_cannot_see_the_deposit_list(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('admin.deposits.index'))->assertForbidden();
    }

    public function test_a_staff_user_without_payments_manage_cannot_see_payment_settings(): void
    {
        $preset = $this->grantPreset(['payments.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('admin.payment-settings'))->assertForbidden();
    }

    public function test_saving_payment_settings_is_denied_without_the_permission(): void
    {
        $preset = $this->grantPreset(['payments.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        Livewire::actingAs($staff, 'staff')
            ->test(PaymentSettings::class)
            ->set('webhookSignatureHeader', 'X-Something-Else')
            ->call('save')
            ->assertForbidden();
    }

    public function test_a_super_admin_can_update_the_webhook_signature_header(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);

        Livewire::actingAs($admin, 'staff')
            ->test(PaymentSettings::class)
            ->set('webhookSignatureHeader', 'X-Lolapay-Signature')
            ->set('isActive', false)
            ->call('save');

        $config = PaymentProviderConfig::where('provider', 'alpha_upi')->first();
        $this->assertSame('X-Lolapay-Signature', $config->webhook_signature_header);
        $this->assertFalse($config->is_active);
    }
}
