<?php

namespace Tests\Feature\Leaderboard;

use App\Livewire\Admin\LeaderboardBannerForm;
use App\Livewire\Admin\LeaderboardBannerList;
use App\Models\LeaderboardBanner;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class LeaderboardBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function admin(): StaffUser
    {
        return StaffUser::factory()->superAdmin()->create();
    }

    private function createBanner(string $title = 'Weekend Showdown', int $width = 1920, int $height = 1080): LeaderboardBanner
    {
        Livewire::actingAs($this->admin(), 'staff')
            ->test(LeaderboardBannerForm::class)
            ->set('title', $title)
            ->set('image', UploadedFile::fake()->image('banner.png', $width, $height))
            ->call('save')
            ->assertHasNoErrors();

        return LeaderboardBanner::where('title', $title)->firstOrFail();
    }

    public function test_an_admin_uploads_a_banner_that_is_stored_as_two_16_9_webp_files(): void
    {
        $banner = $this->createBanner('Season opener', 2400, 1200);

        foreach ([$banner->imageAsset, $banner->thumbnailAsset] as $asset) {
            Storage::disk('public')->assertExists($asset->path);
            $this->assertSame('leaderboard_banners', $asset->bucket);
            $this->assertSame('image/webp', $asset->mime_type);
        }

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($banner->imageAsset->path));
        $this->assertSame(1280, $width);
        $this->assertSame(720, $height);
    }

    public function test_invalid_banner_uploads_are_rejected(): void
    {
        $component = Livewire::actingAs($this->admin(), 'staff')->test(LeaderboardBannerForm::class);

        $component->set('image', UploadedFile::fake()->createWithContent('x.jpg', 'not an image'))->assertHasErrors('image');
        $component->set('image', UploadedFile::fake()->image('small.png', 300, 200))->assertHasErrors('image');
        $component->call('save')->assertHasErrors('image');

        $this->assertDatabaseCount('leaderboard_banners', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_replace_hide_reorder_and_delete_manage_the_files(): void
    {
        $first = $this->createBanner('One');
        $second = $this->createBanner('Two');
        $oldPaths = [$first->imageAsset->path, $first->thumbnailAsset->path];

        Livewire::actingAs($this->admin(), 'staff')
            ->test(LeaderboardBannerForm::class, ['banner' => $first])
            ->set('image', UploadedFile::fake()->image('new.jpg', 1600, 900))
            ->call('save')
            ->assertHasNoErrors();

        foreach ($oldPaths as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        $list = Livewire::actingAs($this->admin(), 'staff')->test(LeaderboardBannerList::class);
        $list->call('moveDown', $first->id);
        $this->assertSame(['Two', 'One'], LeaderboardBanner::orderBy('display_order')->pluck('title')->all());

        $list->call('toggle', $second->id);
        $this->assertFalse($second->fresh()->is_active);

        $list->call('delete', $second->id);
        $this->assertDatabaseMissing('leaderboard_banners', ['id' => $second->id]);
        $this->assertCount(2, Storage::disk('public')->allFiles(), 'only the first banner\'s two files remain');
    }

    public function test_the_player_api_returns_enabled_banners_in_admin_order(): void
    {
        $one = $this->createBanner('One');
        $two = $this->createBanner('Two');
        $hidden = $this->createBanner('Hidden');
        $one->update(['display_order' => 2]);
        $two->update(['display_order' => 1]);
        $hidden->update(['is_active' => false, 'display_order' => 0]);
        LeaderboardBanner::create(['title' => 'No picture', 'display_order' => 5]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/leaderboards/banners')->assertOk();

        $this->assertSame(['Two', 'One'], collect($response->json('data'))->pluck('title')->all());
        $this->assertStringEndsWith('.webp', $response->json('data.0.image_url'));
        $this->assertStringContainsString('/storage/storage_assets/leaderboard_banners/', $response->json('data.0.thumbnail_url'));
    }

    public function test_the_banner_route_is_not_swallowed_by_the_period_route_and_requires_login(): void
    {
        $this->getJson('/api/v1/leaderboards/banners')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/leaderboards/banners')->assertOk()->assertJson(['data' => []]);
        $this->getJson('/api/v1/leaderboards/daily')->assertOk();
        $this->getJson('/api/v1/leaderboards/yearly')->assertNotFound();
    }
}
