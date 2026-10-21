<div class="mx-auto max-w-xl">
    <h1 class="mb-6 text-2xl font-bold text-violet-200">Payment settings</h1>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="space-y-4 rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <div>
            <p class="mb-1 text-xs text-violet-300">Provider</p>
            <p class="text-white">Alpha UPI (Lola Pay)</p>
        </div>

        <label class="flex items-center gap-2 text-sm text-violet-200">
            <input type="checkbox" wire:model="isActive">
            Active (players can start new deposits)
        </label>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Webhook signature header</label>
            <input type="text" wire:model="webhookSignatureHeader" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
            @error('webhookSignatureHeader') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-violet-500">
                The provider's own docs disagree on this header's real name — confirm it against a real webhook
                delivery and correct it here if needed. No code change or deploy required.
            </p>
        </div>

        <p class="text-xs text-violet-500">
            API key, API secret, and webhook secret are configured via server environment variables
            (ALPHA_UPI_API_KEY / ALPHA_UPI_API_SECRET / ALPHA_UPI_WEBHOOK_SECRET), never here.
        </p>

        <button type="submit" class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
            Save settings
        </button>
    </form>
</div>
