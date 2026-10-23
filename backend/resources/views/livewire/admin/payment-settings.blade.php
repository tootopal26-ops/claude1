<div>
    <x-ui.flash />

    <x-ui.page-header title="Payment settings" description="How deposits from the payment gateway are accepted and verified." />

    <form wire:submit="save" novalidate>
        <x-ui.form-section title="Provider" description="The gateway players use to add coins to their wallet.">
            <dl class="sm:col-span-2"><x-ui.kv label="Provider">Alpha UPI (Lola Pay)</x-ui.kv></dl>

            <div class="sm:col-span-2">
                <x-ui.switch wire:model="isActive" label="Active" hint="Players can start new deposits." />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Webhook verification" description="The gateway signs every payment notification so we can tell real ones from forged ones.">
            <x-ui.input
                label="Webhook signature header"
                wire:model="webhookSignatureHeader"
                required
                autocomplete="off"
                spellcheck="false"
                wrapperClass="sm:col-span-2"
                hint="The provider's own docs disagree on this header's real name — confirm it against a real webhook delivery and correct it here if needed. No code change or deploy required."
            />
        </x-ui.form-section>

        <x-ui.form-section title="Credentials" description="Secrets are never shown or stored in this panel.">
            <div class="sm:col-span-2">
                <x-ui.alert variant="info" title="Configured on the server">
                    The API key, API secret and webhook secret are set through the server environment variables
                    <code class="rounded bg-surface-3 px-1.5 py-0.5 font-mono text-xs text-fg">ALPHA_UPI_API_KEY</code>,
                    <code class="rounded bg-surface-3 px-1.5 py-0.5 font-mono text-xs text-fg">ALPHA_UPI_API_SECRET</code> and
                    <code class="rounded bg-surface-3 px-1.5 py-0.5 font-mono text-xs text-fg">ALPHA_UPI_WEBHOOK_SECRET</code>, never here.
                </x-ui.alert>
            </div>
        </x-ui.form-section>

        <div class="mm-glass-bar sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-end gap-2 border-t border-line px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <x-ui.button type="submit" loading="save">Save settings</x-ui.button>
        </div>
    </form>
</div>
