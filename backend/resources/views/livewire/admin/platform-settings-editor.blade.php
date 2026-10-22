<div class="mx-auto max-w-2xl">
    <h1 class="mb-6 text-2xl font-bold text-violet-200">Platform settings</h1>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    @if ($formError)
        <div class="mb-4 rounded-lg bg-red-900/40 px-4 py-3 text-sm text-red-300">{{ $formError }}</div>
    @endif

    <form wire:submit="save" class="space-y-5 rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        @foreach ($catalog as $key => $spec)
            <div>
                <label class="mb-1 block text-xs text-violet-300">{{ $spec['label'] }}</label>
                @if ($spec['type'] === 'string_list')
                    <textarea wire:model="values.{{ $key }}" rows="4" placeholder="One per line" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-sm text-white"></textarea>
                @else
                    <input type="number" step="{{ $spec['type'] === 'decimal' ? '0.01' : '1' }}" min="0" wire:model="values.{{ $key }}" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                @endif
            </div>
        @endforeach

        <button type="submit" class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
            Save settings
        </button>
    </form>
</div>
