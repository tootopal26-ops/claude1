<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Game;
use App\Models\RecurringTournamentTemplate;
use App\Support\Portal;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class TemplateForm extends Component
{
    public ?RecurringTournamentTemplate $template = null;

    public ?int $game_id = null;

    public string $title = '';

    public string $description = '';

    public string $mode = 'solo';

    public float $entry_fee = 0;

    public float $per_kill_reward = 0;

    public float $prize_pool = 0;

    public int $total_slots = 48;

    public ?int $min_players = null;

    public string $frequency = 'weekly';

    public ?int $day_of_week = 0;

    public string $time_of_day = '18:00';

    public int $generate_days_ahead = 7;

    public bool $is_active = true;

    public function mount(?RecurringTournamentTemplate $template = null): void
    {
        $this->template = $template;

        if ($template !== null) {
            $this->game_id = $template->game_id;
            $this->title = $template->title;
            $this->description = (string) $template->description;
            $this->mode = $template->mode;
            $this->entry_fee = (float) $template->entry_fee;
            $this->per_kill_reward = (float) $template->per_kill_reward;
            $this->prize_pool = (float) $template->prize_pool;
            $this->total_slots = $template->total_slots;
            $this->min_players = $template->min_players;
            $this->frequency = $template->frequency;
            $this->day_of_week = $template->day_of_week;
            $this->time_of_day = substr($template->time_of_day, 0, 5);
            $this->generate_days_ahead = $template->generate_days_ahead;
            $this->is_active = $template->is_active;
        }
    }

    /**
     * Friendly names for validation messages ("The game field is required").
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'game_id' => 'game',
            'total_slots' => 'total slots',
            'min_players' => 'minimum teams',
            'entry_fee' => 'entry fee',
            'per_kill_reward' => 'per-kill reward',
            'prize_pool' => 'prize pool',
            'day_of_week' => 'day of week',
            'time_of_day' => 'time of day',
            'generate_days_ahead' => 'days ahead',
        ];
    }

    public function save(): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('recurring_tournaments.manage'), 403);

        $validated = $this->validate([
            'game_id' => ['required', 'exists:games,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'mode' => ['required', 'in:solo,duo,squad'],
            'entry_fee' => ['required', 'numeric', 'min:0'],
            'per_kill_reward' => ['required', 'numeric', 'min:0'],
            'prize_pool' => ['required', 'numeric', 'min:0'],
            'total_slots' => ['required', 'integer', 'min:1', 'max:200'],
            'min_players' => ['nullable', 'integer', 'min:1', 'lte:total_slots'],
            'frequency' => ['required', 'in:daily,weekly'],
            'day_of_week' => ['required_if:frequency,weekly', 'nullable', 'integer', 'min:0', 'max:6'],
            'time_of_day' => ['required', 'date_format:H:i'],
            'generate_days_ahead' => ['required', 'integer', 'min:1', 'max:60'],
            'is_active' => ['boolean'],
        ]);

        $validated['day_of_week'] = $validated['frequency'] === 'weekly' ? $validated['day_of_week'] : null;

        $wasExisting = $this->template !== null;
        $template = $this->template ?? new RecurringTournamentTemplate(['created_by_staff_id' => Auth::guard('staff')->id()]);
        $template->fill($validated);
        $template->save();

        AuditLog::record($wasExisting ? 'recurring_template.updated' : 'recurring_template.created', Auth::guard('staff')->user(), $template, [
            'title' => $template->title,
            'is_active' => $template->is_active,
        ]);

        session()->flash('status', "Template \"{$template->title}\" saved.");

        $this->redirectRoute(Portal::routeName('templates.index'), navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.template-form', [
            'games' => Game::where('is_active', true)->orderBy('display_order')->get(),
        ]);
    }
}
