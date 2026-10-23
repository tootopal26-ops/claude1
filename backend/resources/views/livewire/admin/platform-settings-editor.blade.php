@php
    // Presentation grouping only. Every catalog setting still renders: anything not listed here falls into "Other settings".
    $groups = [
        ['title' => 'Eligibility', 'description' => 'Rules applied when a new player registers. Existing players are not affected.', 'keys' => ['min_age', 'dob_exclusion_window_days']],
        ['title' => 'Region restrictions', 'description' => 'Regions where registration is blocked. This is a compliance decision for the operator.', 'keys' => ['blocked_states']],
        ['title' => 'Risk monitoring', 'description' => 'When the platform raises an alert for staff to review.', 'keys' => ['wallet_risk_alert_threshold']],
    ];

    $grouped = collect($groups)->flatMap(fn ($group) => $group['keys'])->all();
    $leftover = collect(array_keys($catalog))->reject(fn ($key) => in_array($key, $grouped, true))->values()->all();

    if ($leftover !== []) {
        $groups[] = ['title' => 'Other settings', 'description' => null, 'keys' => $leftover];
    }

    $hints = [
        'min_age' => 'Registration is refused below this age.',
        'dob_exclusion_window_days' => 'Refuses a date of birth whose day and month fall within this many days from today (inclusive), whatever the year.',
        'wallet_risk_alert_threshold' => 'A manual wallet adjustment at or above this many coins raises a high-priority risk alert for review.',
    ];
@endphp

<div>
    <x-ui.flash />

    <x-ui.page-header title="Platform settings" description="Platform-wide rules that take effect as soon as they are saved." />

    @if ($formError)
        <x-ui.alert variant="danger" title="Settings weren't saved" class="mb-6">{{ $formError }}</x-ui.alert>
    @endif

    <x-ui.alert variant="warning" title="Changes apply immediately" class="mb-6">
        Saving updates sign-up eligibility and risk monitoring for the whole platform right away. Each change is recorded in the audit log with its previous and new value.
    </x-ui.alert>

    <form wire:submit="save" novalidate>
        @foreach ($groups as $group)
            <x-ui.form-section :title="$group['title']" :description="$group['description']" wire:key="settings-group-{{ $loop->index }}">
                @foreach ($group['keys'] as $key)
                    @php
                        $spec = $catalog[$key];
                        $label = \Illuminate\Support\Str::before($spec['label'], ' — ');
                        $hint = $hints[$key] ?? (str_contains($spec['label'], ' — ') ? ucfirst(\Illuminate\Support\Str::after($spec['label'], ' — ')) : null);
                    @endphp

                    @if ($spec['type'] === 'string_list')
                        <x-ui.textarea :label="$label" wire:model="values.{{ $key }}" rows="5" placeholder="One per line" :hint="$hint" wrapperClass="sm:col-span-2" />
                    @else
                        <x-ui.input :label="$label" type="number" min="0" step="{{ $spec['type'] === 'decimal' ? '0.01' : '1' }}" wire:model="values.{{ $key }}" :hint="$hint" />
                    @endif
                @endforeach
            </x-ui.form-section>
        @endforeach

        <div class="sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-between gap-3 border-t border-line bg-app px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <p class="hidden text-xs text-fg-3 sm:block">Color theme and font are personal preferences — change them from the Appearance menu in the header.</p>
            <x-ui.button
                type="submit"
                class="ml-auto"
                loading="save"
                icon="check"
                confirm="Apply these settings now? They take effect immediately for new sign-ups and risk monitoring."
                confirm-title="Save platform settings"
                confirm-label="Save settings"
            >Save settings</x-ui.button>
        </div>
    </form>
</div>
