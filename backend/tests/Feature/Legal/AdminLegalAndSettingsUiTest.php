<?php

namespace Tests\Feature\Legal;

use App\Livewire\Admin\LegalDocumentForm;
use App\Livewire\Admin\PlatformSettingsEditor;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLegalAndSettingsUiTest extends TestCase
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

    public function test_the_legal_document_index_requires_legal_manage(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('staff.legal.index'))->assertForbidden();
    }

    public function test_publishing_a_document_is_denied_without_legal_manage(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        Livewire::actingAs($staff, 'staff')
            ->test(LegalDocumentForm::class)
            ->set('title', 'Terms')
            ->set('content', 'Body')
            ->call('publish')
            ->assertForbidden();

        $this->assertDatabaseMissing('legal_documents', ['title' => 'Terms']);
    }

    public function test_a_staff_user_with_legal_manage_can_publish(): void
    {
        $preset = $this->grantPreset(['legal.manage']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        Livewire::actingAs($staff, 'staff')
            ->test(LegalDocumentForm::class)
            ->set('title', 'Terms')
            ->set('content', 'Body')
            ->call('publish')
            ->assertRedirect(route('staff.legal.index'));

        $this->assertDatabaseHas('legal_documents', ['title' => 'Terms', 'is_current' => true]);
    }

    public function test_the_settings_page_requires_settings_manage(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('staff.settings.index'))->assertForbidden();
    }

    public function test_saving_settings_is_denied_without_settings_manage(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        Livewire::actingAs($staff, 'staff')
            ->test(PlatformSettingsEditor::class)
            ->call('save')
            ->assertForbidden();
    }

    public function test_a_staff_user_with_settings_manage_can_save(): void
    {
        $preset = $this->grantPreset(['settings.manage']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        Livewire::actingAs($staff, 'staff')
            ->test(PlatformSettingsEditor::class)
            ->set('values.min_age', '21')
            ->set('values.blocked_states', "Telangana\nAssam")
            ->call('save');

        $this->assertSame(21, config('minimax.min_age'));
        $this->assertSame(['Telangana', 'Assam'], config('minimax.blocked_states'));
    }

    public function test_saving_a_negative_number_is_rejected(): void
    {
        $preset = $this->grantPreset(['settings.manage']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        Livewire::actingAs($staff, 'staff')
            ->test(PlatformSettingsEditor::class)
            ->set('values.min_age', '-5')
            ->call('save')
            ->assertSet('formError', fn ($message) => $message !== null);

        $this->assertDatabaseMissing('platform_settings', ['key' => 'min_age']);
    }
}
