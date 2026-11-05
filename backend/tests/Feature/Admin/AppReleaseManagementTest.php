<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AppReleaseForm;
use App\Livewire\Admin\AppReleaseList;
use App\Models\AppRelease;
use App\Models\AppReleaseDownload;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin > App Releases: the only way the privately-distributed Android build becomes
 * reachable by players (no Play Store listing exists for this project).
 */
class AppReleaseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function admin(): StaffUser
    {
        return StaffUser::factory()->superAdmin()->create();
    }

    private function releaseManager(): StaffUser
    {
        $preset = PermissionPreset::create(['name' => 'Releases']);
        $preset->permissions()->attach(Permission::firstOrCreate(
            ['key' => 'app_releases.manage'],
            ['label' => 'App releases', 'group' => 'Operations']
        ));

        return StaffUser::factory()->create(['permission_preset_id' => $preset->id]);
    }

    private function upload(StaffUser $staff, string $versionName, int $versionCode, bool $makeCurrent = true)
    {
        return Livewire::actingAs($staff, 'staff')
            ->test(AppReleaseForm::class)
            ->set('versionName', $versionName)
            ->set('versionCode', $versionCode)
            ->set('releaseNotes', 'Bug fixes and improvements.')
            ->set('makeCurrent', $makeCurrent)
            ->set('file', UploadedFile::fake()->create('mini-max.apk', 2048, 'application/vnd.android.package-archive'))
            ->call('save');
    }

    public function test_uploading_a_release_and_marking_it_current_creates_the_record_and_audit_entries(): void
    {
        $staff = $this->releaseManager();

        $this->upload($staff, '1.0.0', 100)
            ->assertHasNoErrors()
            ->assertRedirect(route('staff.app-releases.index'));

        $release = AppRelease::where('version_code', 100)->firstOrFail();
        $this->assertSame('1.0.0', $release->version_name);
        $this->assertTrue($release->is_current);
        $this->assertNotNull($release->fileAsset);
        $this->assertSame('android', $release->platform);

        $this->assertTrue(AuditLog::where('action', 'app_release.published')->exists());
        $this->assertTrue(AuditLog::where('action', 'app_release.set_current')->exists());
    }

    public function test_only_one_release_per_platform_is_ever_current(): void
    {
        $staff = $this->releaseManager();

        $this->upload($staff, '1.0.0', 100)->assertHasNoErrors();
        $first = AppRelease::where('version_code', 100)->firstOrFail();

        $this->upload($staff, '1.1.0', 110)->assertHasNoErrors();
        $second = AppRelease::where('version_code', 110)->firstOrFail();

        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->fresh()->is_current);
    }

    public function test_a_duplicate_version_code_for_the_same_platform_is_rejected(): void
    {
        $staff = $this->releaseManager();

        $this->upload($staff, '1.0.0', 100)->assertHasNoErrors();
        $this->upload($staff, '1.0.1', 100)->assertHasErrors('versionCode');

        $this->assertSame(1, AppRelease::count());
    }

    public function test_the_current_release_cannot_be_deleted_without_switching_current_first(): void
    {
        $staff = $this->releaseManager();
        $this->upload($staff, '1.0.0', 100)->assertHasNoErrors();
        $release = AppRelease::where('version_code', 100)->firstOrFail();

        Livewire::actingAs($staff, 'staff')
            ->test(AppReleaseList::class)
            ->call('delete', $release->id);

        $this->assertNotNull($release->fresh());
    }

    public function test_setting_a_different_release_current_then_deleting_the_old_one_removes_it(): void
    {
        $staff = $this->releaseManager();
        $this->upload($staff, '1.0.0', 100)->assertHasNoErrors();
        $old = AppRelease::where('version_code', 100)->firstOrFail();

        $this->upload($staff, '1.1.0', 110)->assertHasNoErrors();

        Livewire::actingAs($staff, 'staff')
            ->test(AppReleaseList::class)
            ->call('delete', $old->id);

        $this->assertNull(AppRelease::find($old->id));
    }

    public function test_the_public_download_endpoint_serves_only_the_current_release_and_logs_it(): void
    {
        $staff = $this->releaseManager();
        $this->upload($staff, '1.0.0', 100)->assertHasNoErrors();

        $response = $this->get('/download/android');
        $response->assertOk();

        $release = AppRelease::where('version_code', 100)->firstOrFail();
        $this->assertSame(1, $release->fresh()->download_count);
        $this->assertSame(1, AppReleaseDownload::where('app_release_id', $release->id)->count());
    }

    public function test_the_public_download_endpoint_404s_when_no_release_is_current(): void
    {
        $this->get('/download/android')->assertNotFound();
    }

    public function test_app_releases_manage_is_required_to_reach_the_pages(): void
    {
        $noAccess = StaffUser::factory()->create();

        $this->actingAs($noAccess, 'staff')->get(route('staff.app-releases.index'))->assertForbidden();
        $this->actingAs($noAccess, 'staff')->get(route('staff.app-releases.create'))->assertForbidden();
    }
}
