<?php

namespace Tests\Feature\Tournament;

use App\Livewire\Admin\TemplateForm;
use App\Livewire\Admin\TemplateList;
use App\Models\Game;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\RecurringTournamentTemplate;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTemplateManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_staff_user_without_the_permission_cannot_view_the_template_list(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('staff.templates.index'))->assertForbidden();
    }

    public function test_a_super_admin_can_create_a_recurring_template(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $game = Game::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(TemplateForm::class)
            ->set('game_id', $game->id)
            ->set('title', 'Nightly Solo Cup')
            ->set('frequency', 'daily')
            ->set('time_of_day', '20:00')
            ->call('save')
            ->assertRedirect(route('admin.templates.index'));

        $this->assertDatabaseHas('recurring_tournament_templates', [
            'title' => 'Nightly Solo Cup',
            'frequency' => 'daily',
            'day_of_week' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'recurring_template.created', 'actor_id' => $staff->id]);
    }

    public function test_saving_a_weekly_template_stores_the_day_of_week(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $game = Game::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(TemplateForm::class)
            ->set('game_id', $game->id)
            ->set('title', 'Weekend Squad Clash')
            ->set('mode', 'squad')
            ->set('frequency', 'weekly')
            ->set('day_of_week', 6)
            ->set('time_of_day', '18:00')
            ->call('save');

        $this->assertDatabaseHas('recurring_tournament_templates', [
            'title' => 'Weekend Squad Clash',
            'frequency' => 'weekly',
            'day_of_week' => 6,
        ]);
    }

    public function test_saving_is_denied_without_the_permission(): void
    {
        $preset = PermissionPreset::create(['name' => 'no-templates']);
        $permission = Permission::firstOrCreate(['key' => 'tournaments.view'], ['label' => 'x', 'group' => 'Test']);
        $preset->permissions()->attach($permission);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $game = Game::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(TemplateForm::class)
            ->set('game_id', $game->id)
            ->set('title', 'Blocked Template')
            ->set('time_of_day', '20:00')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('recurring_tournament_templates', ['title' => 'Blocked Template']);
    }

    public function test_generate_now_creates_occurrences_and_is_denied_without_the_permission(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');
        $template = RecurringTournamentTemplate::create([
            'game_id' => Game::factory()->create()->id,
            'title' => 'On-demand Cup',
            'mode' => 'solo',
            'entry_fee' => 0,
            'total_slots' => 48,
            'frequency' => 'daily',
            'time_of_day' => '10:00',
            'generate_days_ahead' => 1,
            'is_active' => true,
        ]);

        $preset = PermissionPreset::create(['name' => 'no-templates-2']);
        $permission = Permission::firstOrCreate(['key' => 'tournaments.view'], ['label' => 'x', 'group' => 'Test']);
        $preset->permissions()->attach($permission);
        $unauthorizedStaff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        Livewire::actingAs($unauthorizedStaff, 'staff')
            ->test(TemplateList::class)
            ->call('generateNow', $template->id)
            ->assertForbidden();

        $this->assertDatabaseCount('tournaments', 0);

        $admin = StaffUser::factory()->create(['is_super_admin' => true]);

        Livewire::actingAs($admin, 'staff')
            ->test(TemplateList::class)
            ->call('generateNow', $template->id)
            ->assertSee('Generated 2');

        $this->assertDatabaseCount('tournaments', 2);
    }
}
