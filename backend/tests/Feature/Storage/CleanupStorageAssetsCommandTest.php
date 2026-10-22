<?php

namespace Tests\Feature\Storage;

use App\Domain\Storage\StorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupStorageAssetsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_deletes_expired_assets_and_reports_the_count(): void
    {
        Storage::fake('local');

        $expired = app(StorageService::class)->store(
            UploadedFile::fake()->create('old.jpg', 50, 'image/jpeg'),
            'tournament_evidence',
        );
        $expired->created_at = now()->subDays(200);
        $expired->save();

        $fresh = app(StorageService::class)->store(
            UploadedFile::fake()->create('new.jpg', 50, 'image/jpeg'),
            'tournament_evidence',
        );

        $this->artisan('storage:cleanup')
            ->expectsOutputToContain('1 storage asset(s) deleted.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('storage_assets', ['id' => $expired->id]);
        $this->assertDatabaseHas('storage_assets', ['id' => $fresh->id]);
    }
}
