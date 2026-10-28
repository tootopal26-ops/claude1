<?php

namespace App\Livewire\Admin\Ads;

use App\Domain\Ads\AdsConfig;
use App\Domain\Settings\PlatformSettingsService;
use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Livewire\Admin\Ads\Concerns\EditsSettings;
use App\Models\AuditLog;
use App\Models\Tournament;
use App\Models\TournamentAdTicket;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ads & Monetization > Tournament tickets: the default and maximum rewarded-ad tickets for
 * free tournaments, and each upcoming free tournament's requirement and progress. Tickets
 * only unlock the normal Join; paid tournaments never ask for them.
 */
#[Layout('components.layouts.admin')]
class TicketSettings extends Component
{
    use AdsPage;
    use EditsSettings;

    /** @var array<int, string> */
    public array $required = [];

    protected function settingKeys(): array
    {
        return ['tickets_default_free', 'tickets_max_per_tournament'];
    }

    public function mount(PlatformSettingsService $settings): void
    {
        $this->loadSettings($settings);
        foreach ($this->tournaments() as $tournament) {
            $this->required[$tournament->id] = (string) $tournament->required_ad_tickets;
        }
    }

    public function save(PlatformSettingsService $settings): void
    {
        $this->saveSettings($settings, 'Ticket settings saved.');
    }

    public function saveTournament(int $id): void
    {
        $this->ensureCanManage();
        $tournament = Tournament::findOrFail($id);
        $max = (int) config('minimax.tickets_max_per_tournament');
        $raw = trim($this->required[$id] ?? '');

        if (! $tournament->isFree() || $tournament->status !== 'upcoming') {
            $this->addError("required.{$id}", 'Only an upcoming free tournament can ask for tickets.');

            return;
        }
        if (filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < 0 || (int) $raw > $max) {
            $this->addError("required.{$id}", "Enter 0 to {$max}.");

            return;
        }

        $previous = $tournament->required_ad_tickets;
        $tournament->update(['required_ad_tickets' => (int) $raw]);
        AuditLog::record('ads.tickets.tournament_updated', $this->actor(), $tournament, ['previous' => $previous, 'new' => (int) $raw]);
        session()->flash('status', "\"{$tournament->title}\" now needs {$raw} ticket(s).");
    }

    public function render()
    {
        $tournaments = $this->tournaments();
        $tickets = TournamentAdTicket::query()
            ->whereIn('tournament_id', $tournaments->pluck('id'))
            ->selectRaw('tournament_id, user_id, COUNT(*) as held')
            ->groupBy('tournament_id', 'user_id')
            ->get()
            ->groupBy('tournament_id');

        return view('livewire.admin.ads.ticket-settings', [
            'tournaments' => $tournaments,
            'tickets' => $tickets,
            'history' => $this->settingsHistory(),
            'placementLive' => app(AdsConfig::class)->isPurposeActive('tournament_ticket'),
        ]);
    }

    /**
     * @return Collection<int, Tournament>
     */
    private function tournaments(): Collection
    {
        return Tournament::query()
            ->where('status', 'upcoming')
            ->where('entry_fee', 0)
            ->withCount('entries')
            ->orderBy('scheduled_start_at')
            ->limit(50)
            ->get();
    }
}
