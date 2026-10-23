<?php

namespace App\Livewire\Admin;

use App\Domain\Leaderboard\LeaderboardPrizeService;
use App\Models\LeaderboardPeriod;
use App\Models\LeaderboardPrizeRule;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Prize editor for one leaderboard period type at a time. Each type has its own
 * rule (never shared). Saving changes prizes for periods that finalize later; it
 * never pays anyone and never touches a period that already finalized.
 */
#[Layout('components.layouts.admin')]
class LeaderboardPrizes extends Component
{
    #[Url(as: 'type')]
    public string $type = 'daily';

    public bool $enabled = true;

    public string $first = '0';

    public string $second = '0';

    public string $third = '0';

    /** What ranks 4-100 pay once the bulk amount has been applied. */
    public string $rest = '0';

    /** The amount typed into the bulk box, not applied until the admin confirms. */
    public string $bulkAmount = '';

    public bool $restIsUniform = true;

    /** Not named `dirty`: $wire.dirty is a built-in Livewire helper and would shadow it in the browser. */
    public bool $hasChanges = false;

    public function mount(LeaderboardPrizeService $prizes): void
    {
        $this->authorizeAction();

        if (! in_array($this->type, LeaderboardPrizeService::TYPES, true)) {
            $this->type = 'daily';
        }

        $this->load($prizes);
    }

    public function updated(string $property): void
    {
        if ($property !== 'bulkAmount') {
            $this->refreshDirty(app(LeaderboardPrizeService::class));
        }
    }

    public function applyBulk(LeaderboardPrizeService $prizes): void
    {
        $this->authorizeAction();

        $this->validate([
            'bulkAmount' => ['required', 'integer', 'min:0', 'max:'.LeaderboardPrizeService::MAX_COINS],
        ], [], ['bulkAmount' => 'amount for ranks 4–100']);

        $this->rest = (string) (int) $this->bulkAmount;
        $this->restIsUniform = true;
        $this->bulkAmount = '';
        $this->refreshDirty($prizes);
    }

    public function save(LeaderboardPrizeService $prizes): void
    {
        $staff = $this->authorizeAction();

        $this->validate($this->rules(), [], [
            'first' => 'rank 1 prize', 'second' => 'rank 2 prize', 'third' => 'rank 3 prize', 'rest' => 'prize for ranks 4–100',
        ]);

        $prizes->save($this->type, $this->enabled, (int) $this->first, (int) $this->second, (int) $this->third, (int) $this->rest, $staff);

        $label = ucfirst($this->type);
        session()->flash('status', "{$label} prizes saved. They apply to the next {$this->type} period that finalizes; periods already finalized keep the prizes they closed with.");

        $this->load($prizes);
    }

    public function cancel(LeaderboardPrizeService $prizes): void
    {
        $this->authorizeAction();
        $this->resetValidation();
        $this->load($prizes);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        $coins = ['required', 'integer', 'min:0', 'max:'.LeaderboardPrizeService::MAX_COINS];

        return ['enabled' => ['boolean'], 'first' => $coins, 'second' => $coins, 'third' => $coins, 'rest' => $coins];
    }

    private function load(LeaderboardPrizeService $prizes): void
    {
        $saved = $prizes->draft($this->type);

        $this->enabled = $saved['enabled'];
        $this->first = (string) $saved['first'];
        $this->second = (string) $saved['second'];
        $this->third = (string) $saved['third'];
        $this->rest = (string) $saved['rest'];
        $this->restIsUniform = $saved['restIsUniform'];
        $this->bulkAmount = '';
        $this->hasChanges = false;
    }

    private function refreshDirty(LeaderboardPrizeService $prizes): void
    {
        $saved = $prizes->draft($this->type);

        $this->hasChanges = $this->enabled !== $saved['enabled']
            || (string) $this->first !== (string) $saved['first']
            || (string) $this->second !== (string) $saved['second']
            || (string) $this->third !== (string) $saved['third']
            || (string) $this->rest !== (string) $saved['rest']
            || ! $saved['restIsUniform'] && $this->restIsUniform;
    }

    private function authorizeAction()
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff->hasPermission('leaderboards.manage'), 403);

        return $staff;
    }

    public function render(LeaderboardPrizeService $prizes)
    {
        $this->authorizeAction();

        $numeric = fn (string $value) => ctype_digit($value) ? min((int) $value, LeaderboardPrizeService::MAX_COINS) : 0;
        $table = $prizes->expand($numeric($this->first), $numeric($this->second), $numeric($this->third), $numeric($this->rest));

        return view('livewire.admin.leaderboard-prizes', [
            'table' => $table,
            'totalIfFull' => array_sum($table),
            'maxRank' => LeaderboardPrizeRule::MAX_RANK,
            'lastFinalized' => LeaderboardPeriod::where('type', $this->type)->where('status', 'finalized')->orderByDesc('period_start')->first(),
        ]);
    }
}
