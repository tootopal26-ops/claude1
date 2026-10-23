<?php

namespace Tests\Feature\Leaderboard;

use App\Livewire\Admin\LeaderboardBannerForm;
use App\Livewire\Admin\LeaderboardBannerList;
use App\Models\LeaderboardBanner;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Home hero banners share the leaderboard banner system: same 16:9 pipeline and pages,
 * separated by placement, with an optional link the app opens on tap.
 */
class HomeBannerTest extends TestCase
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

    private function createHomeBanner(string $title, string $link = ''): LeaderboardBanner
    {
        Livewire::actingAs($this->admin(), 'staff')
            ->test(LeaderboardBannerForm::class, ['placement' => 'home'])
            ->set('title', $title)
            ->set('linkUrl', $link)
            ->set('image', UploadedFile::fake()->image('hero.png', 1920, 1080))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.home-banners.index'));

        return LeaderboardBanner::where('title', $title)->firstOrFail();
    }

    public function test_home_banners_are_kept_apart_from_leaderboard_banners_in_the_api(): void
    {
        $home = $this->createHomeBanner('Grand Finals', '/tournaments?status=upcoming');
        Livewire::actingAs($this->admin(), 'staff')
            ->test(LeaderboardBannerForm::class)
            ->set('title', 'Leaderboard only')
            ->set('image', UploadedFile::fake()->image('lb.png', 1920, 1080))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('home', $home->placement);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/banners/home')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Grand Finals')
            ->assertJsonPath('data.0.link_url', '/tournaments?status=upcoming');

        $this->getJson('/api/v1/leaderboards/banners')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Leaderboard only');
    }

    public function test_only_app_screens_or_https_addresses_are_accepted_as_links(): void
    {
        foreach (['/tournaments', '/tournaments?status=completed', '/game/12', '/tournament/7', '/wallet', 'https://minimax.example/finals'] as $index => $link) {
            $this->assertSame($link, $this->createHomeBanner("Valid {$index}", $link)->link_url);
        }

        foreach (['javascript:alert(1)', 'http://insecure.example', '/admin', '/game/abc', '/tournaments?status=all', 'ftp://files.example', 'minimax://wallet'] as $link) {
            Livewire::actingAs($this->admin(), 'staff')
                ->test(LeaderboardBannerForm::class, ['placement' => 'home'])
                ->set('linkUrl', $link)
                ->set('image', UploadedFile::fake()->image('hero.png', 1920, 1080))
                ->call('save')
                ->assertHasErrors('linkUrl');
        }
    }

    public function test_reordering_moves_a_banner_only_among_banners_of_its_own_placement(): void
    {
        $first = $this->createHomeBanner('Home one');
        $second = $this->createHomeBanner('Home two');
        $leaderboard = LeaderboardBanner::create(['placement' => 'leaderboard', 'title' => 'LB', 'is_active' => true, 'display_order' => 1, 'focal_x' => 50, 'focal_y' => 50]);

        Livewire::actingAs($this->admin(), 'staff')
            ->test(LeaderboardBannerList::class, ['placement' => 'home'])
            ->assertSee('Home one')
            ->assertDontSee('>LB<', false)
            ->call('moveDown', $first->id);

        $this->assertSame([2, 1], [$first->fresh()->display_order, $second->fresh()->display_order]);
        $this->assertSame(1, $leaderboard->fresh()->display_order);
    }

    public function test_home_banners_need_games_manage_and_cannot_be_edited_from_the_leaderboard_pages(): void
    {
        $banner = $this->createHomeBanner('Grand Finals');

        $gamesOnly = StaffUser::factory()->create(['permission_preset_id' => tap(PermissionPreset::create(['name' => 'Games']), function ($preset) {
            $preset->permissions()->attach(Permission::firstOrCreate(['key' => 'games.manage'], ['label' => 'Games', 'group' => 'Tournaments']));
        })->id]);

        $this->actingAs($gamesOnly, 'staff')->get(route('staff.home-banners.index'))->assertOk()->assertSee('Home banners')->assertSee('Grand Finals');
        $this->actingAs($gamesOnly, 'staff')->get(route('staff.home-banners.create'))->assertOk();
        $this->actingAs($gamesOnly, 'staff')->get(route('staff.leaderboards.banners.index'))->assertForbidden();

        $this->actingAs($this->admin(), 'staff')->get(route('admin.leaderboards.banners.edit', $banner))->assertNotFound();
        $this->actingAs($this->admin(), 'staff')->get(route('admin.home-banners.edit', $banner))->assertOk()->assertSee('Grand Finals');
    }
}
