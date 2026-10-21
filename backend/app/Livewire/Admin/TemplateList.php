<?php

namespace App\Livewire\Admin;

use App\Domain\Tournament\GenerateTournamentsFromTemplatesAction;
use App\Models\RecurringTournamentTemplate;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class TemplateList extends Component
{
    public ?string $generateStatus = null;

    public function generateNow(GenerateTournamentsFromTemplatesAction $action, int $templateId): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('recurring_tournaments.manage'), 403);

        $template = RecurringTournamentTemplate::findOrFail($templateId);
        $result = $action->execute($template);

        $this->generateStatus = "Generated {$result['generated']}, skipped {$result['skipped_conflicts']} conflict(s).";
    }

    public function render()
    {
        $templates = RecurringTournamentTemplate::with('game')->withCount('generatedTournaments')->orderBy('title')->get();

        return view('livewire.admin.template-list', compact('templates'));
    }
}
