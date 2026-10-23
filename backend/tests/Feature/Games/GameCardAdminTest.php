<?php

namespace Tests\Feature\Games;

use App\Livewire\Admin\GameForm;
use App\Livewire\Admin\GameList;
use App\Models\Game;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\Models\StorageAsset;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class GameCardAdminTest extends TestCase
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

    private function staffWith(string ...$keys): StaffUser
    {
        $preset = PermissionPreset::create(['name' => 'Preset '.uniqid()]);

        foreach ($keys as $key) {
            $preset->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'Test']));
        }

        return StaffUser::factory()->create(['permission_preset_id' => $preset->id]);
    }

    private function image(int $width = 1280, int $height = 720, string $name = 'card.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    private function createCard(string $name = 'Free Fire', array $overrides = []): Game
    {
        Livewire::actingAs($this->admin(), 'staff')
            ->test(GameForm::class)
            ->set('name', $name)
            ->set('image', $this->image())
            ->call('save')
            ->assertHasNoErrors();

        return Game::where('name', $name)->firstOrFail();
    }

    public function test_an_admin_creates_a_card_with_a_real_processed_image(): void
    {
        Livewire::actingAs($this->admin(), 'staff')
            ->test(GameForm::class)
            ->set('name', 'Free Fire')
            ->set('description', 'Battle royale')
            ->set('image', $this->image(1600, 1200))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.tournament-games.index'));

        $game = Game::firstOrFail();
        $this->assertSame('Free Fire', $game->name);
        $this->assertSame('Battle royale', $game->description);
        $this->assertTrue($game->is_active);
        $this->assertNotNull($game->image_asset_id);
        $this->assertNotNull($game->thumbnail_asset_id);

        foreach ([$game->imageAsset, $game->thumbnailAsset] as $asset) {
            Storage::disk('public')->assertExists($asset->path);
            $this->assertSame('image/webp', $asset->mime_type);
            $this->assertSame('public', $asset->disk);
            $this->assertSame('game_cards', $asset->bucket);
            $this->assertSame($game->id, $asset->subject_id);
            $this->assertStringNotContainsString('card.jpg', $asset->path, 'the client filename must never reach the path');
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'game_card.created']);
    }

    public function test_an_image_is_required_for_a_new_card(): void
    {
        Livewire::actingAs($this->admin(), 'staff')
            ->test(GameForm::class)
            ->set('name', 'No picture')
            ->call('save')
            ->assertHasErrors(['image' => 'required']);

        $this->assertDatabaseCount('games', 0);
    }

    public function test_uploads_that_are_not_valid_images_are_rejected_and_nothing_is_stored(): void
    {
        $component = Livewire::actingAs($this->admin(), 'staff')->test(GameForm::class)->set('name', 'Bad uploads');

        $component->set('image', UploadedFile::fake()->createWithContent('evil.jpg', "<?php echo 'x';"))->assertHasErrors('image');
        $component->set('image', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))->assertHasErrors('image');
        $component->set('image', $this->image(400, 300))->assertHasErrors('image');
        $component->set('image', UploadedFile::fake()->image('huge.png', 1280, 720)->size(9000))->assertHasErrors('image');

        $component->call('save')->assertHasErrors('image');

        $this->assertDatabaseCount('games', 0);
        $this->assertDatabaseCount('storage_assets', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_editing_without_a_new_image_keeps_the_current_files(): void
    {
        $game = $this->createCard();
        $before = [$game->image_asset_id, $game->thumbnail_asset_id];

        Livewire::actingAs($this->admin(), 'staff')
            ->test(GameForm::class, ['game' => $game])
            ->set('name', 'Free Fire MAX')
            ->set('isActive', false)
            ->call('save')
            ->assertHasNoErrors();

        $game->refresh();
        $this->assertSame('Free Fire MAX', $game->name);
        $this->assertFalse($game->is_active);
        $this->assertSame($before, [$game->image_asset_id, $game->thumbnail_asset_id]);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_replacing_the_image_removes_the_old_files_and_keeps_only_the_new_ones(): void
    {
        $game = $this->createCard();
        $oldPaths = [$game->imageAsset->path, $game->thumbnailAsset->path];

        Livewire::actingAs($this->admin(), 'staff')
            ->test(GameForm::class, ['game' => $game])
            ->set('image', $this->image(1920, 1080, 'replacement.png'))
            ->call('save')
            ->assertHasNoErrors();

        $game->refresh();

        foreach ($oldPaths as $old) {
            Storage::disk('public')->assertMissing($old);
        }

        $this->assertDatabaseCount('storage_assets', 2);
        $this->assertCount(2, Storage::disk('public')->allFiles());
        Storage::disk('public')->assertExists($game->imageAsset->path);
    }

    public function test_deleting_a_card_removes_its_record_and_files(): void
    {
        $game = $this->createCard();
        $paths = [$game->imageAsset->path, $game->thumbnailAsset->path];

        Livewire::actingAs($this->admin(), 'staff')->test(GameList::class)->call('delete', $game->id);

        $this->assertDatabaseMissing('games', ['id' => $game->id]);
        $this->assertDatabaseCount('storage_assets', 0);

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'game_card.deleted']);
    }

    public function test_a_card_that_tournaments_use_cannot_be_deleted(): void
    {
        $game = $this->createCard();
        Tournament::factory()->create(['game_id' => $game->id]);

        Livewire::actingAs($this->admin(), 'staff')
            ->test(GameList::class)
            ->call('delete', $game->id)
            ->assertHasErrors('delete');

        $this->assertDatabaseHas('games', ['id' => $game->id]);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_a_card_can_be_hidden_and_shown_without_deleting_it(): void
    {
        $game = $this->createCard();
        $list = Livewire::actingAs($this->admin(), 'staff')->test(GameList::class);

        $list->call('toggle', $game->id);
        $this->assertFalse($game->fresh()->is_active);

        $list->call('toggle', $game->id);
        $this->assertTrue($game->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'game_card.disabled']);
    }

    public function test_moving_cards_swaps_neighbours_and_keeps_a_gap_free_sequence(): void
    {
        $a = Game::factory()->create(['name' => 'A', 'display_order' => 1]);
        $b = Game::factory()->create(['name' => 'B', 'display_order' => 5]);
        $c = Game::factory()->create(['name' => 'C', 'display_order' => 9]);
        $list = Livewire::actingAs($this->admin(), 'staff')->test(GameList::class);

        $list->call('moveUp', $c->id);
        $this->assertSame(['A', 'C', 'B'], Game::orderBy('display_order')->pluck('name')->all());
        $this->assertSame([1, 2, 3], Game::orderBy('display_order')->pluck('display_order')->all());

        $list->call('moveDown', $a->id);
        $this->assertSame(['C', 'A', 'B'], Game::orderBy('display_order')->pluck('name')->all());

        $list->call('moveUp', $c->id); // already first: no change
        $this->assertSame(['C', 'A', 'B'], Game::orderBy('display_order')->pluck('name')->all());
    }

    public function test_the_list_can_be_searched_by_title(): void
    {
        Game::factory()->create(['name' => 'Free Fire']);
        Game::factory()->create(['name' => 'Chess Blitz']);

        Livewire::actingAs($this->admin(), 'staff')
            ->test(GameList::class)
            ->set('search', 'chess')
            ->assertSee('Chess Blitz')
            ->assertDontSee('Free Fire');
    }

    public function test_the_section_needs_the_games_manage_permission_in_both_portals(): void
    {
        $nobody = $this->staffWith('tournaments.view');

        $this->actingAs($nobody, 'staff')->get(route('staff.tournament-games.index'))->assertForbidden();
        $this->actingAs($nobody, 'staff')->get(route('staff.tournament-games.create'))->assertForbidden();
        Livewire::actingAs($nobody, 'staff')->test(GameList::class)->assertForbidden();

        $manager = $this->staffWith('games.manage');
        $this->actingAs($manager, 'staff')->get(route('staff.tournament-games.index'))->assertOk();
        $this->actingAs($manager, 'staff')->get(route('admin.tournament-games.index'))->assertForbidden();
        $this->actingAs($this->admin(), 'staff')->get(route('admin.tournament-games.index'))->assertOk()->assertSee('Tournament games');
    }

    public function test_the_player_api_lists_only_enabled_cards_in_the_admin_order_with_real_image_urls(): void
    {
        $first = $this->createCard('Second place');
        $second = $this->createCard('First place');
        $hidden = $this->createCard('Hidden card');

        $first->update(['display_order' => 2]);
        $second->update(['display_order' => 1]);
        $hidden->update(['is_active' => false, 'display_order' => 0]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/games')->assertOk();

        $this->assertSame(['First place', 'Second place'], collect($response->json('data'))->pluck('title')->all());

        $card = $response->json('data.0');
        $this->assertStringStartsWith('http', $card['image_url']);
        $this->assertStringEndsWith('.webp', $card['image_url']);
        $this->assertStringContainsString('/storage/storage_assets/game_cards/', $card['thumbnail_url']);
        $this->assertNotSame($card['image_url'], $card['thumbnail_url']);
    }

    public function test_the_games_api_requires_a_signed_in_player(): void
    {
        $this->getJson('/api/v1/games')->assertUnauthorized();
    }

    public function test_tournaments_can_be_filtered_by_game(): void
    {
        $fireGame = Game::factory()->create();
        $chessGame = Game::factory()->create();
        Tournament::factory()->create(['game_id' => $fireGame->id, 'title' => 'Fire Cup']);
        Tournament::factory()->create(['game_id' => $chessGame->id, 'title' => 'Chess Cup']);

        Sanctum::actingAs(User::factory()->create());

        $this->assertSame(['Fire Cup'], collect($this->getJson('/api/v1/tournaments?game_id='.$fireGame->id)->json('data'))->pluck('title')->all());
        $this->assertCount(2, $this->getJson('/api/v1/tournaments')->json('data'));
    }

    public function test_game_card_assets_are_never_removed_by_storage_cleanup(): void
    {
        $this->createCard();
        StorageAsset::query()->update(['created_at' => now()->subYears(5)]);

        $this->artisan('storage:cleanup')->assertSuccessful();

        $this->assertDatabaseCount('storage_assets', 2);
    }
}
