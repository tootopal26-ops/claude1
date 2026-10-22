<?php

namespace Tests\Feature\Storage;

use App\Domain\Storage\CleanupStorageAssetsAction;
use App\Domain\Storage\StorageService;
use App\Models\RiskAlert;
use App\Models\StorageAsset;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupStorageAssetsActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeAsset(string $bucket, ?Tournament $tournament = null): StorageAsset
    {
        $file = UploadedFile::fake()->create('proof-'.uniqid().'.jpg', 50, 'image/jpeg');

        return app(StorageService::class)->store($file, $bucket, $tournament);
    }

    public function test_an_expired_asset_is_deleted(): void
    {
        $asset = $this->makeAsset('tournament_evidence');
        $asset->created_at = now()->subDays(181);
        $asset->save();

        $deleted = app(CleanupStorageAssetsAction::class)->execute();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('storage_assets', ['id' => $asset->id]);
    }

    public function test_an_asset_not_yet_past_retention_is_kept(): void
    {
        $asset = $this->makeAsset('tournament_evidence');
        $asset->created_at = now()->subDays(10);
        $asset->save();

        $deleted = app(CleanupStorageAssetsAction::class)->execute();

        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas('storage_assets', ['id' => $asset->id]);
    }

    public function test_a_manually_retained_asset_is_never_deleted(): void
    {
        $asset = $this->makeAsset('tournament_evidence');
        $asset->created_at = now()->subDays(181);
        $asset->retained_reason = 'Active dispute #1';
        $asset->save();

        $deleted = app(CleanupStorageAssetsAction::class)->execute();

        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas('storage_assets', ['id' => $asset->id]);
    }

    public function test_an_asset_tied_to_an_open_risk_alert_is_never_deleted(): void
    {
        $tournament = Tournament::factory()->create();
        $asset = $this->makeAsset('tournament_evidence', $tournament);
        $asset->created_at = now()->subDays(181);
        $asset->save();

        RiskAlert::create([
            'type' => 'test_alert',
            'risk_level' => 'medium',
            'subject_type' => $tournament->getMorphClass(),
            'subject_id' => $tournament->id,
        ]);

        $deleted = app(CleanupStorageAssetsAction::class)->execute();

        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas('storage_assets', ['id' => $asset->id]);
    }

    public function test_an_asset_whose_risk_alert_was_reviewed_becomes_eligible_again(): void
    {
        $tournament = Tournament::factory()->create();
        $asset = $this->makeAsset('tournament_evidence', $tournament);
        $asset->created_at = now()->subDays(181);
        $asset->save();

        $alert = RiskAlert::create([
            'type' => 'test_alert',
            'risk_level' => 'medium',
            'subject_type' => $tournament->getMorphClass(),
            'subject_id' => $tournament->id,
        ]);
        $alert->reviewed_at = now();
        $alert->save();

        $deleted = app(CleanupStorageAssetsAction::class)->execute();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('storage_assets', ['id' => $asset->id]);
    }

    public function test_a_bucket_with_no_retention_window_is_never_cleaned_up(): void
    {
        $asset = $this->makeAsset('staff_documents');
        $asset->created_at = now()->subYears(5);
        $asset->save();

        $deleted = app(CleanupStorageAssetsAction::class)->execute();

        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas('storage_assets', ['id' => $asset->id]);
    }

    public function test_rerunning_finds_nothing_more_to_delete(): void
    {
        $asset = $this->makeAsset('tournament_evidence');
        $asset->created_at = now()->subDays(181);
        $asset->save();

        $action = app(CleanupStorageAssetsAction::class);
        $first = $action->execute();
        $second = $action->execute();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
    }
}
