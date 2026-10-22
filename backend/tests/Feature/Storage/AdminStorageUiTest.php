<?php

namespace Tests\Feature\Storage;

use App\Domain\Storage\StorageService;
use App\Livewire\Admin\StorageDashboard;
use App\Livewire\Admin\TournamentResults;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\Models\StorageAsset;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminStorageUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function grantPreset(array $keys): PermissionPreset
    {
        $preset = PermissionPreset::create(['name' => implode('+', $keys).'-'.uniqid()]);

        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'Test']);
            $preset->permissions()->attach($permission);
        }

        return $preset;
    }

    public function test_uploading_evidence_is_denied_without_the_permission(): void
    {
        $preset = $this->grantPreset(['results.submit']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $tournament = Tournament::factory()->create(['status' => 'ongoing']);

        Livewire::actingAs($staff, 'staff')
            ->test(TournamentResults::class, ['tournament' => $tournament])
            ->set('evidenceFile', UploadedFile::fake()->create('proof.jpg', 100, 'image/jpeg'))
            ->call('uploadEvidence')
            ->assertForbidden();

        $this->assertSame(0, StorageAsset::count());
    }

    public function test_a_staff_user_with_the_permission_can_upload_and_delete_evidence(): void
    {
        $preset = $this->grantPreset(['results.submit', 'results.upload_attachments']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $tournament = Tournament::factory()->create(['status' => 'ongoing']);

        Livewire::actingAs($staff, 'staff')
            ->test(TournamentResults::class, ['tournament' => $tournament])
            ->set('evidenceFile', UploadedFile::fake()->create('proof.jpg', 100, 'image/jpeg'))
            ->call('uploadEvidence');

        $asset = StorageAsset::where('subject_id', $tournament->id)->where('bucket', 'tournament_evidence')->firstOrFail();
        Storage::disk('local')->assertExists($asset->path);

        Livewire::actingAs($staff, 'staff')
            ->test(TournamentResults::class, ['tournament' => $tournament])
            ->call('deleteEvidence', $asset->id);

        $this->assertDatabaseMissing('storage_assets', ['id' => $asset->id]);
    }

    public function test_a_disallowed_file_shows_a_validation_error_and_is_not_stored(): void
    {
        $preset = $this->grantPreset(['results.submit', 'results.upload_attachments']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $tournament = Tournament::factory()->create(['status' => 'ongoing']);

        Livewire::actingAs($staff, 'staff')
            ->test(TournamentResults::class, ['tournament' => $tournament])
            ->set('evidenceFile', UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'))
            ->call('uploadEvidence')
            ->assertSet('evidenceError', fn ($message) => $message !== null);

        $this->assertSame(0, StorageAsset::count());
    }

    public function test_the_storage_dashboard_requires_storage_view(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('admin.storage.index'))->assertForbidden();
    }

    public function test_retaining_and_deleting_an_asset_is_denied_without_storage_manage(): void
    {
        $preset = $this->grantPreset(['storage.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $asset = $this->makeAsset();

        Livewire::actingAs($staff, 'staff')
            ->test(StorageDashboard::class)
            ->call('retain', $asset->id)
            ->assertForbidden();

        Livewire::actingAs($staff, 'staff')
            ->test(StorageDashboard::class)
            ->call('delete', $asset->id)
            ->assertForbidden();

        $this->assertDatabaseHas('storage_assets', ['id' => $asset->id, 'retained_reason' => null]);
    }

    public function test_a_staff_user_with_storage_manage_can_retain_unretain_and_delete(): void
    {
        $preset = $this->grantPreset(['storage.view', 'storage.manage']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $asset = $this->makeAsset();

        Livewire::actingAs($staff, 'staff')
            ->test(StorageDashboard::class)
            ->set('retainReason', 'Active dispute #42')
            ->call('retain', $asset->id);

        $this->assertDatabaseHas('storage_assets', ['id' => $asset->id, 'retained_reason' => 'Active dispute #42']);

        Livewire::actingAs($staff, 'staff')
            ->test(StorageDashboard::class)
            ->call('unretain', $asset->id);

        $this->assertDatabaseHas('storage_assets', ['id' => $asset->id, 'retained_reason' => null]);

        Livewire::actingAs($staff, 'staff')
            ->test(StorageDashboard::class)
            ->call('delete', $asset->id);

        $this->assertDatabaseMissing('storage_assets', ['id' => $asset->id]);
    }

    public function test_downloading_an_asset_requires_the_buckets_view_permission(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);
        $asset = $this->makeAsset();

        $this->actingAs($staff, 'staff')->get(route('admin.storage-assets.download', $asset))->assertForbidden();

        $preset = $this->grantPreset(['results.upload_attachments']);
        $staff->permission_preset_id = $preset->id;
        $staff->save();
        $staff->load('permissionPreset');

        $this->actingAs($staff, 'staff')->get(route('admin.storage-assets.download', $asset))->assertOk();
    }

    private function makeAsset(): StorageAsset
    {
        $file = UploadedFile::fake()->create('proof.jpg', 50, 'image/jpeg');

        return app(StorageService::class)->store($file, 'tournament_evidence');
    }
}
