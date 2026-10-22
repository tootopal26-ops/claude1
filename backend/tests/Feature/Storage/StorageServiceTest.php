<?php

namespace Tests\Feature\Storage;

use App\Domain\Storage\StorageService;
use App\Models\RiskAlert;
use App\Models\StaffUser;
use App\Models\StorageAsset;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StorageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_storing_a_valid_file_creates_an_asset_and_writes_it_to_disk(): void
    {
        $tournament = Tournament::factory()->create();
        $staff = StaffUser::factory()->create();
        $file = UploadedFile::fake()->create('proof.jpg', 100, 'image/jpeg');

        $asset = app(StorageService::class)->store($file, 'tournament_evidence', $tournament, $staff);

        $this->assertSame('tournament_evidence', $asset->bucket);
        $this->assertSame(Tournament::class, $asset->subject_type);
        $this->assertSame($tournament->id, $asset->subject_id);
        $this->assertSame($staff->id, $asset->uploaded_by_staff_id);
        $this->assertSame('image/jpeg', $asset->mime_type);
        $this->assertSame('proof.jpg', $asset->original_filename);
        Storage::disk('local')->assertExists($asset->path);
    }

    public function test_a_disallowed_mime_type_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload');

        $this->expectException(ValidationException::class);

        try {
            app(StorageService::class)->store($file, 'tournament_evidence');
        } finally {
            $this->assertSame(0, StorageAsset::count());
        }
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        // tournament_evidence's configured max is 8192 KB.
        $file = UploadedFile::fake()->create('huge.jpg', 9000, 'image/jpeg');

        $this->expectException(ValidationException::class);

        try {
            app(StorageService::class)->store($file, 'tournament_evidence');
        } finally {
            $this->assertSame(0, StorageAsset::count());
        }
    }

    public function test_an_unknown_bucket_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('proof.jpg', 10, 'image/jpeg');

        $this->expectException(ValidationException::class);
        app(StorageService::class)->store($file, 'not_a_real_bucket');
    }

    public function test_the_stored_path_is_opaque_and_does_not_use_the_client_filename(): void
    {
        $file = UploadedFile::fake()->create('../../etc/passwd.jpg', 10, 'image/jpeg');

        $asset = app(StorageService::class)->store($file, 'tournament_evidence');

        $this->assertStringNotContainsString('..', $asset->path);
        $this->assertStringNotContainsString('/etc/', $asset->path);
        $this->assertStringStartsWith('storage_assets/tournament_evidence/', $asset->path);
        $this->assertSame('passwd.jpg', $asset->original_filename);
    }

    public function test_a_dangerous_original_filename_is_sanitized_for_display(): void
    {
        $file = UploadedFile::fake()->create('evil<script>alert(1).jpg', 10, 'image/jpeg');

        $asset = app(StorageService::class)->store($file, 'tournament_evidence');

        $this->assertDoesNotMatchRegularExpression('/[<>]/', $asset->original_filename);
    }

    public function test_retain_requires_a_non_empty_reason(): void
    {
        $asset = $this->makeAsset();
        $actor = StaffUser::factory()->create();

        $this->expectException(ValidationException::class);
        app(StorageService::class)->retain($asset, '  ', $actor);
    }

    public function test_retain_and_unretain_round_trip(): void
    {
        $asset = $this->makeAsset();
        $actor = StaffUser::factory()->create();

        $retained = app(StorageService::class)->retain($asset, 'Active dispute #9', $actor);
        $this->assertTrue($retained->isRetained());
        $this->assertDatabaseHas('audit_logs', ['action' => 'storage.asset_retained']);

        $unretained = app(StorageService::class)->unretain($retained, $actor);
        $this->assertFalse($unretained->isRetained());
        $this->assertDatabaseHas('audit_logs', ['action' => 'storage.asset_unretained']);
    }

    public function test_delete_removes_the_file_and_the_row(): void
    {
        $asset = $this->makeAsset();
        $path = $asset->path;
        $actor = StaffUser::factory()->create();

        app(StorageService::class)->delete($asset, $actor);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('storage_assets', ['id' => $asset->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'storage.asset_deleted']);
    }

    public function test_an_asset_is_protected_while_its_subjects_risk_alert_is_unreviewed(): void
    {
        $tournament = Tournament::factory()->create();
        $asset = $this->makeAsset($tournament);

        $alert = RiskAlert::create([
            'type' => 'test_alert',
            'risk_level' => 'medium',
            'subject_type' => $tournament->getMorphClass(),
            'subject_id' => $tournament->id,
        ]);

        $this->assertTrue(app(StorageService::class)->isProtectedByOpenRiskAlert($asset));

        $alert->reviewed_at = now();
        $alert->save();

        $this->assertFalse(app(StorageService::class)->isProtectedByOpenRiskAlert($asset->fresh()));
    }

    public function test_an_asset_with_no_subject_is_never_protected_by_a_risk_alert(): void
    {
        $asset = $this->makeAsset();

        $this->assertFalse(app(StorageService::class)->isProtectedByOpenRiskAlert($asset));
    }

    public function test_usage_by_bucket_aggregates_count_and_size_including_empty_buckets(): void
    {
        $this->makeAsset();
        $this->makeAsset();

        $usage = app(StorageService::class)->usageByBucket()->keyBy('bucket');

        $this->assertSame(2, $usage['tournament_evidence']['count']);
        $this->assertGreaterThan(0, $usage['tournament_evidence']['total_bytes']);
        $this->assertSame(0, $usage['withdrawal_proof']['count']);
    }

    private function makeAsset(?Tournament $tournament = null): StorageAsset
    {
        $file = UploadedFile::fake()->create('proof-'.uniqid().'.jpg', 50, 'image/jpeg');

        return app(StorageService::class)->store($file, 'tournament_evidence', $tournament);
    }
}
