<div>
    <x-ui.flash />

    <x-ui.page-header title="Customer Support" description="Where the app's Profile > Customer Support row takes a player." />

    <form wire:submit="save" novalidate>
        <x-ui.form-section title="Current link" description="What players open right now.">
            <dl class="sm:col-span-2">
                <x-ui.kv label="Support link">
                    @if ($savedUrl)
                        <span class="break-all font-mono text-xs text-fg" data-testid="saved-support-url">{{ $savedUrl }}</span>
                    @else
                        <span class="text-fg-3" data-testid="saved-support-url">Not set — the app tells players support is not available yet.</span>
                    @endif
                </x-ui.kv>
            </dl>

            @if ($savedUrl)
                <div class="sm:col-span-2">
                    <a href="{{ $savedUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-text hover:underline">
                        <x-icon name="external" :size="16" />
                        Test the link
                    </a>
                </div>
            @endif
        </x-ui.form-section>

        <x-ui.form-section title="Edit" description="A help page, support website or approved support channel.">
            <x-ui.input
                label="Support link"
                type="url"
                inputmode="url"
                wire:model="supportUrl"
                autocomplete="off"
                spellcheck="false"
                placeholder="https://help.example.com"
                wrapperClass="sm:col-span-2"
                hint="Must start with https:// (or mailto: for an email address). Other kinds of links are refused so the app never opens an unsafe target. Leave empty to turn the row off."
            />
        </x-ui.form-section>

        <div class="mm-glass-bar sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-end gap-2 border-t border-line px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <x-ui.button type="submit" loading="save" icon="check">Save link</x-ui.button>
        </div>
    </form>
</div>
