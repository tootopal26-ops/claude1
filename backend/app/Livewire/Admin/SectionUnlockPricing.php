<?php

namespace App\Livewire\Admin;

use App\Domain\Sections\SectionPricingService;
use App\Domain\Sections\SectionRegistry;
use App\Models\AuditLog;
use App\Models\SectionUnlock;
use App\Models\SectionUnlockPrice;
use App\Support\Portal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Admin > Section Unlock Pricing: the coin price of each registered section and the
 * switch that turns paid unlocking on. Only sections in the registry can be chosen.
 */
#[Layout('components.layouts.admin')]
class SectionUnlockPricing extends Component
{
    #[Url(as: 'section')]
    public string $sectionKey = '';

    public bool $enabled = false;

    public string $price = '';

    /** Not named `dirty`: $wire.dirty is a built-in Livewire helper and would shadow it in the browser. */
    public bool $hasChanges = false;

    public function mount(SectionRegistry $registry): void
    {
        $this->authorizeAction();

        if (! $registry->has($this->sectionKey)) {
            $this->sectionKey = $registry->keys()[0] ?? '';
        }

        $this->load();
    }

    public function updatedSectionKey(SectionRegistry $registry): void
    {
        if (! $registry->has($this->sectionKey)) {
            $this->sectionKey = $registry->keys()[0] ?? '';
        }

        $this->resetValidation();
        $this->load();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['price', 'enabled'], true)) {
            $this->refreshDirty();
        }
    }

    public function cancel(): void
    {
        $this->resetValidation();
        $this->load();
    }

    public function save(SectionPricingService $pricing): void
    {
        $staff = $this->authorizeAction();

        $validated = $this->validate([
            'sectionKey' => ['required', 'in:'.implode(',', app(SectionRegistry::class)->keys())],
            'price' => ['required', 'integer', 'min:1', 'max:'.SectionPricingService::MAX_PRICE],
            'enabled' => ['boolean'],
        ], [
            'price.required' => 'Enter the unlock price in coins, even while paid unlocking is off.',
        ], ['sectionKey' => 'section', 'price' => 'unlock price']);

        $changed = $pricing->save($staff, $validated['sectionKey'], (int) $validated['price'], $this->enabled);

        $this->load();
        session()->flash('status', $changed ? 'Saved. The new price applies to the next unlock; players who already unlocked keep access.' : 'Nothing to save — this is already what is stored.');
        $this->redirectRoute(Portal::routeName('section-unlock-pricing.index'), ['section' => $this->sectionKey], navigate: false);
    }

    private function load(): void
    {
        $row = $this->sectionKey === '' ? null : SectionUnlockPrice::where('section_key', $this->sectionKey)->first();

        $this->enabled = (bool) $row?->is_enabled;
        $this->price = $row ? (string) $row->price_coins : '';
        $this->hasChanges = false;
    }

    private function refreshDirty(): void
    {
        $row = SectionUnlockPrice::where('section_key', $this->sectionKey)->first();

        $this->hasChanges = $this->enabled !== (bool) $row?->is_enabled
            || $this->price !== ($row ? (string) $row->price_coins : '');
    }

    private function authorizeAction()
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff->hasPermission('section_unlocks.manage'), 403);

        return $staff;
    }

    public function render(SectionRegistry $registry)
    {
        $this->authorizeAction();

        $prices = SectionUnlockPrice::query()->with('updatedBy')->get()->keyBy('section_key');
        $unlocks = SectionUnlock::query()
            ->select('section_key', DB::raw('count(*) as players'), DB::raw('sum(price_paid) as coins'))
            ->groupBy('section_key')
            ->get()
            ->keyBy('section_key');

        $sections = collect($registry->all())->map(fn (array $meta, string $key) => $meta + [
            'key' => $key,
            'saved' => $prices->get($key),
            'players' => (int) ($unlocks->get($key)->players ?? 0),
            'coins' => (int) ($unlocks->get($key)->coins ?? 0),
        ])->values();

        $draftPrice = ctype_digit($this->price) && (int) $this->price >= 1 ? (int) $this->price : null;
        $saved = $prices->get($this->sectionKey);

        return view('livewire.admin.section-unlock-pricing', [
            'sections' => $sections,
            'selected' => $sections->firstWhere('key', $this->sectionKey),
            'draftPrice' => $draftPrice,
            'savedLabel' => $saved && $saved->is_enabled ? 'Unlock · '.number_format($saved->price_coins).' Coins' : null,
            'turnsOn' => $this->enabled && ! ($saved?->is_enabled),
            'history' => AuditLog::query()
                ->where('action', 'section_unlock.updated')
                ->with('actor')
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }
}
