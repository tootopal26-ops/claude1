<div>
    <x-ui.page-header
        title="Publish new version"
        description="Write the next version of a legal document. Players read and accept exactly what you publish here."
        :breadcrumbs="[
            ['label' => 'Legal documents', 'url' => portal_route('legal.index')],
            ['label' => 'Publish new version'],
        ]"
    />

    @if ($formError)
        <x-ui.alert variant="danger" title="That version couldn't be published" class="mb-6">{{ $formError }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <form wire:submit="publish" class="min-w-0 space-y-6 xl:col-span-2" novalidate>
            <x-ui.card title="Document">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.select label="Document type" wire:model.live="type" required hint="Switching type loads that document's current version as a starting point.">
                        <option value="terms_and_conditions">Terms &amp; Conditions</option>
                        <option value="privacy_policy">Privacy Policy</option>
                    </x-ui.select>

                    <x-ui.input label="Title" wire:model="title" required />

                    <x-ui.textarea label="Content" wire:model="content" required rows="18" class="font-mono text-[13px] leading-relaxed" wrapperClass="sm:col-span-2" hint="This is the full text players will read and accept." />
                </div>
            </x-ui.card>

            <div class="flex items-center justify-end gap-2">
                <x-ui.button :href="portal_route('legal.index')" variant="ghost">Cancel</x-ui.button>
                <x-ui.button
                    type="submit"
                    loading="publish"
                    icon="shield"
                    confirm="Publishing a new version requires every player to re-accept it before they can continue. Proceed?"
                    confirm-title="Publish new version"
                    confirm-label="Publish version"
                >Publish version</x-ui.button>
            </div>
        </form>

        <div class="min-w-0 space-y-6 xl:sticky xl:top-20 xl:self-start">
            <x-ui.alert variant="warning" title="Every player must re-accept">
                Publishing creates a new version. Players who accepted an earlier version are asked to accept this one before they can continue.
            </x-ui.alert>

            <x-ui.card title="What happens when you publish">
                <ul class="space-y-3 text-sm text-fg-2">
                    <li class="flex gap-2.5"><x-icon name="check-circle" :size="16" class="mt-0.5 shrink-0 text-success" /><span>A new version number is created and becomes the current one.</span></li>
                    <li class="flex gap-2.5"><x-icon name="check-circle" :size="16" class="mt-0.5 shrink-0 text-success" /><span>Earlier versions are kept unchanged on file for audit and disputes.</span></li>
                    <li class="flex gap-2.5"><x-icon name="check-circle" :size="16" class="mt-0.5 shrink-0 text-success" /><span>The publication is recorded in the audit log.</span></li>
                </ul>
            </x-ui.card>
        </div>
    </div>
</div>
