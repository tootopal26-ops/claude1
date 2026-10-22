<div class="mx-auto max-w-3xl">
    <a href="{{ route('admin.legal.index') }}" class="text-sm text-violet-400 hover:text-violet-200">&larr; Back to legal documents</a>

    <h1 class="mb-6 mt-2 text-2xl font-bold text-violet-200">Publish new version</h1>

    @if ($formError)
        <div class="mb-4 rounded-lg bg-red-900/40 px-4 py-3 text-sm text-red-300">{{ $formError }}</div>
    @endif

    <form wire:submit="publish" class="space-y-4 rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <div>
            <label class="mb-1 block text-xs text-violet-300">Document type</label>
            <select wire:model.live="type" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                <option value="terms_and_conditions">Terms & Conditions</option>
                <option value="privacy_policy">Privacy Policy</option>
            </select>
        </div>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Title</label>
            <input type="text" wire:model="title" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
            @error('title') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Content</label>
            <textarea wire:model="content" rows="16" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 font-mono text-sm text-white"></textarea>
            @error('content') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <button type="submit" wire:confirm="Publishing a new version requires every player to re-accept it before they can continue. Proceed?" class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
            Publish version
        </button>
    </form>
</div>
