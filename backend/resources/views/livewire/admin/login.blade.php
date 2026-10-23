<div class="grid min-h-screen lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
    {{-- Brand panel --}}
    <aside class="relative hidden overflow-hidden border-r border-line bg-sidebar lg:flex lg:flex-col lg:justify-between lg:p-12" aria-hidden="true">
        <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(60% 45% at 15% 0%, color-mix(in oklab, var(--mm-brand) 20%, transparent), transparent 70%), radial-gradient(45% 35% at 100% 100%, color-mix(in oklab, var(--mm-brand) 10%, transparent), transparent 70%);"></div>
        <div class="pointer-events-none absolute inset-0 opacity-[0.35]" style="background-image: linear-gradient(var(--mm-border) 1px, transparent 1px), linear-gradient(90deg, var(--mm-border) 1px, transparent 1px); background-size: 56px 56px; mask-image: radial-gradient(70% 60% at 30% 30%, #000, transparent 75%); -webkit-mask-image: radial-gradient(70% 60% at 30% 30%, #000, transparent 75%);"></div>

        <div class="relative flex items-center gap-3">
            <x-logo-asset :height="40" />
            <span class="text-lg font-semibold tracking-tight text-fg">Mini Max</span>
        </div>

        <div class="relative max-w-md">
            <p class="text-xs font-semibold uppercase tracking-widest text-brand-text">Esports operations</p>
            <h2 class="mt-3 text-3xl font-semibold leading-tight tracking-tight text-fg">Run every tournament from one place.</h2>
            <ul class="mt-8 space-y-4 text-sm text-fg-2">
                <li class="flex gap-3"><x-icon name="tournaments" :size="18" class="mt-0.5 shrink-0 text-brand-text" /><span>Schedule tournaments, manage entries and publish results.</span></li>
                <li class="flex gap-3"><x-icon name="wallet" :size="18" class="mt-0.5 shrink-0 text-brand-text" /><span>Review deposits and settle withdrawals with a full audit trail.</span></li>
                <li class="flex gap-3"><x-icon name="shield" :size="18" class="mt-0.5 shrink-0 text-brand-text" /><span>Role-based access keeps every staff member to their own work.</span></li>
            </ul>
        </div>

        <p class="relative text-xs text-fg-3">&copy; {{ date('Y') }} Mini Max. Authorized personnel only.</p>
    </aside>

    {{-- Sign-in panel --}}
    <main class="flex items-center justify-center px-5 py-10 sm:px-8">
        <div class="w-full max-w-sm">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <x-logo-asset :height="36" />
                <span class="text-base font-semibold tracking-tight text-fg">Mini Max</span>
            </div>

            <p class="inline-flex items-center gap-2 rounded-full bg-brand-soft px-3 py-1 text-xs font-medium text-brand-text">
                <x-icon name="shield" :size="12" /> Admin &amp; Staff portal
            </p>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-fg">Sign in</h1>
            <p class="mt-1.5 text-sm text-fg-2">Use your Mini Max staff credentials to continue.</p>

            @if (session('status'))
                <x-ui.alert variant="success" class="mt-6">{{ session('status') }}</x-ui.alert>
            @elseif (request()->boolean('expired'))
                <x-ui.alert variant="warning" title="Your session expired" class="mt-6">For your security you were signed out after a period of inactivity. Sign in again to continue — anything you hadn't saved was not submitted.</x-ui.alert>
            @endif

            <form wire:submit="login" class="mt-6 space-y-5" novalidate>
                <x-ui.input label="Username" wire:model="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required class="h-11" />

                <div x-data="{ show: false }">
                    <x-ui.field id="f-password" label="Password" error="password" required>
                        <div class="relative">
                            <input
                                id="f-password"
                                wire:model="password"
                                :type="show ? 'text' : 'password'"
                                type="password"
                                autocomplete="current-password"
                                aria-required="true"
                                aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                                class="mm-control h-11 pr-11"
                            >
                            <button type="button" @click="show = ! show" :aria-pressed="show" :aria-label="show ? 'Hide password' : 'Show password'" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-fg-3 hover:text-fg">
                                <x-icon name="eye" :size="18" x-show="! show" />
                                <x-icon name="eye-off" :size="18" x-show="show" x-cloak />
                            </button>
                        </div>
                    </x-ui.field>
                </div>

                <x-ui.button type="submit" size="lg" class="w-full" loading="login">Sign in</x-ui.button>
            </form>

            <p class="mt-8 text-xs leading-relaxed text-fg-3">
                Trouble signing in? Ask a Mini Max administrator to check that your account is active. For security, sessions end after a period of inactivity.
            </p>
        </div>
    </main>
</div>
