<div class="mx-auto mt-16 max-w-sm rounded-2xl border border-violet-900/40 bg-white/5 p-8 backdrop-blur">
    <h1 class="text-center text-2xl font-bold text-violet-200">Mini Max</h1>
    <p class="mb-6 text-center text-sm text-violet-400">Admin &amp; Staff sign in</p>

    <form wire:submit="login" class="space-y-4">
        <div>
            <label class="mb-1 block text-xs text-violet-300">Username</label>
            <input type="text" wire:model="username" autofocus
                class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white outline-none focus:border-violet-400">
            @error('username') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Password</label>
            <input type="password" wire:model="password"
                class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white outline-none focus:border-violet-400">
        </div>

        <button type="submit"
            class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
            Sign in
        </button>
    </form>
</div>
