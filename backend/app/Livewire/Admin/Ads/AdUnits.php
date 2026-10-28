<?php

namespace App\Livewire\Admin\Ads;

use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Models\AdUnit;
use App\Models\AuditLog;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ads & Monetization > Ad units: the LevelPlay ad unit IDs the app loads, per format and
 * environment. They are public identifiers (they ship inside every app), so they live in
 * the database instead of the app's code; secrets never do. The AdMob / Unity Ads fields
 * only record which network instances sit behind the unit in the LevelPlay dashboard.
 */
#[Layout('components.layouts.admin')]
class AdUnits extends Component
{
    use AdsPage;

    public ?int $editingId = null;

    public string $name = '';

    public string $format = 'rewarded';

    public string $environment = 'production';

    public string $levelplayAdUnitId = '';

    public string $admobReference = '';

    public string $unityReference = '';

    public bool $isActive = true;

    public function edit(int $id): void
    {
        $this->ensureCanManage();
        $unit = AdUnit::findOrFail($id);
        $this->editingId = $unit->id;
        $this->name = $unit->name;
        $this->format = $unit->format;
        $this->environment = $unit->environment;
        $this->levelplayAdUnitId = $unit->levelplay_ad_unit_id;
        $this->admobReference = (string) $unit->admob_reference;
        $this->unityReference = (string) $unit->unity_reference;
        $this->isActive = $unit->is_active;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'format', 'environment', 'levelplayAdUnitId', 'admobReference', 'unityReference', 'isActive']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->ensureCanManage();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'format' => ['required', Rule::in(['rewarded', 'interstitial', 'banner', 'native'])],
            'environment' => ['required', Rule::in(['development', 'staging', 'production'])],
            // LevelPlay ad unit IDs are short alphanumeric tokens; nothing that could be a URL or script.
            'levelplayAdUnitId' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/',
                Rule::unique('ad_units', 'levelplay_ad_unit_id')->where('environment', $this->environment)->ignore($this->editingId)],
            'admobReference' => ['nullable', 'string', 'max:160', 'regex:/^[A-Za-z0-9_\-~\/.:]*$/'],
            'unityReference' => ['nullable', 'string', 'max:160', 'regex:/^[A-Za-z0-9_\-~\/.:]*$/'],
            'isActive' => ['boolean'],
        ], [
            'levelplayAdUnitId.regex' => 'Use the ad unit ID exactly as LevelPlay shows it (letters, numbers, - and _).',
            'levelplayAdUnitId.unique' => 'This ad unit ID is already set up for that environment.',
        ], ['levelplayAdUnitId' => 'LevelPlay ad unit ID', 'admobReference' => 'AdMob reference', 'unityReference' => 'Unity Ads reference']);

        $attributes = [
            'name' => trim($validated['name']),
            'format' => $validated['format'],
            'environment' => $validated['environment'],
            'levelplay_ad_unit_id' => trim($validated['levelplayAdUnitId']),
            'admob_reference' => $validated['admobReference'] ?: null,
            'unity_reference' => $validated['unityReference'] ?: null,
            'is_active' => $validated['isActive'],
            'updated_by_staff_id' => $this->actor()->id,
        ];

        if ($this->editingId) {
            $unit = AdUnit::findOrFail($this->editingId);
            if ($unit->format !== $attributes['format'] && $unit->placements()->exists()) {
                $this->addError('format', 'This unit is used by a placement. Detach it there before changing its format.');

                return;
            }
            $previous = $unit->only(array_keys($attributes));
            $unit->update($attributes);
            AuditLog::record('ads.ad_unit.updated', $this->actor(), $unit, ['previous' => $previous, 'new' => $unit->only(array_keys($attributes))]);
            session()->flash('status', "Ad unit \"{$unit->name}\" saved.");
        } else {
            $unit = AdUnit::create($attributes);
            AuditLog::record('ads.ad_unit.created', $this->actor(), $unit, ['new' => $unit->only(array_keys($attributes))]);
            session()->flash('status', "Ad unit \"{$unit->name}\" added.");
        }

        $this->cancel();
    }

    public function toggle(int $id): void
    {
        $this->ensureCanManage();
        $unit = AdUnit::findOrFail($id);
        $unit->update(['is_active' => ! $unit->is_active, 'updated_by_staff_id' => $this->actor()->id]);
        AuditLog::record($unit->is_active ? 'ads.ad_unit.activated' : 'ads.ad_unit.deactivated', $this->actor(), $unit, ['levelplay_ad_unit_id' => $unit->levelplay_ad_unit_id]);
    }

    public function render()
    {
        return view('livewire.admin.ads.ad-units', [
            'units' => AdUnit::withCount('placements')->orderBy('format')->orderBy('name')->get(),
        ]);
    }
}
