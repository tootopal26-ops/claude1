{{-- Admin portal sign-in: an immersive, split "control center" composition. --}}
<div class="mm-portal mm-portal--admin relative isolate min-h-dvh overflow-hidden bg-app">
    {{-- Background: two restrained purple washes, a fine grid and concentric arena rings. --}}
    <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
        <div class="absolute inset-0" style="background: radial-gradient(60% 55% at 8% -8%, color-mix(in oklab, var(--mm-brand) 17%, transparent), transparent 70%), radial-gradient(45% 45% at 100% 105%, color-mix(in oklab, var(--mm-brand) 10%, transparent), transparent 72%);"></div>
        <div class="absolute inset-0 opacity-[0.28]" style="background-image: linear-gradient(var(--mm-border) 1px, transparent 1px), linear-gradient(90deg, var(--mm-border) 1px, transparent 1px); background-size: 64px 64px; -webkit-mask-image: radial-gradient(65% 70% at 25% 35%, #000, transparent 78%); mask-image: radial-gradient(65% 70% at 25% 35%, #000, transparent 78%);"></div>
        <x-portal.arena-rings />
    </div>

    <div class="absolute right-4 top-4 z-10 sm:right-6 sm:top-6">
        <x-ui.mode-toggle />
    </div>

    <main id="main" class="mx-auto flex min-h-dvh w-full max-w-[1400px] flex-col justify-center gap-10 px-5 py-8 sm:px-10 lg:grid lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)] lg:grid-rows-[1fr_auto] lg:gap-x-16 lg:gap-y-0 lg:px-14 lg:py-12">
        {{-- Heading --}}
        <section class="flex flex-col lg:col-start-1 lg:row-start-1 lg:justify-center" aria-labelledby="portal-title">
            <x-portal.brand :size="42" tag="Administration" class="mm-rise lg:absolute lg:left-14 lg:top-12" />

            <div class="mt-10 max-w-xl lg:mt-0">
                <p class="mm-eyebrow mm-rise" style="--mm-delay: 80ms">MINI MAX · CONTROL CENTER</p>
                <h1 id="portal-title" class="mm-rise mt-4 text-balance text-[2.5rem] font-semibold leading-[1.04] tracking-tight text-fg sm:text-5xl lg:text-[4.25rem]" style="--mm-delay: 140ms">Command the Arena.</h1>
                <p class="mm-rise mt-5 max-w-md text-base leading-relaxed text-fg-2 lg:text-lg" style="--mm-delay: 220ms">Your command center for tournaments, players, rewards, and the entire Mini Max ecosystem.</p>
            </div>
        </section>

        {{-- Sign-in --}}
        <section class="flex items-center justify-center lg:col-start-2 lg:row-span-2 lg:row-start-1" aria-labelledby="signin-title">
            <div class="w-full max-w-md">
                <div class="mm-glass mm-rise rounded-2xl p-6 sm:p-9" style="--mm-delay: 260ms">
                    <p class="inline-flex items-center gap-2 rounded-full border border-line-strong bg-surface-2 px-3 py-1 text-[0.6875rem] font-semibold uppercase tracking-[0.2em] text-fg-2">
                        <x-icon name="shield" :size="12" class="text-brand-text" /> ADMINISTRATOR ACCESS
                    </p>
                    <h2 id="signin-title" class="mt-5 text-2xl font-semibold tracking-tight text-fg">Sign in</h2>
                    <p class="mt-1.5 text-sm text-fg-2">Use your administrator credentials to continue.</p>

                    <x-portal.form-alert class="mt-6" />

                    <form wire:submit="login" class="mt-6 space-y-5" novalidate>
                        <fieldset wire:loading.attr="disabled" wire:target="login" class="grid min-w-0 gap-5">
                            <div x-data x-init="if (window.matchMedia('(min-width: 1024px)').matches) $el.querySelector('input').focus()">
                                <x-ui.input label="Username" wire:model="username" type="text" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" inputmode="text" enterkeyhint="next" required class="h-11" />
                            </div>

                            <x-portal.password-field />
                        </fieldset>

                        <x-ui.button type="submit" size="lg" class="w-full" loading="login">Enter Control Center</x-ui.button>
                    </form>
                </div>

                <p class="mm-rise mt-6 text-center text-xs text-fg-3" style="--mm-delay: 340ms">
                    Staff member? <a href="{{ route('staff.login') }}" class="font-medium text-brand-text underline-offset-4 hover:underline">Open the Staff portal</a>
                </p>
                <p class="mm-rise mt-3 text-center text-xs text-fg-3" style="--mm-delay: 380ms">Authorized administrators only · Mini Max</p>
            </div>
        </section>

        {{-- Quote --}}
        <figure class="mm-rise max-w-lg border-l-2 border-brand/60 pl-5 lg:col-start-1 lg:row-start-2 lg:mt-12" style="--mm-delay: 320ms">
            <blockquote>
                <p class="text-base italic leading-relaxed text-fg-2">&ldquo;Great arenas aren&rsquo;t built by chance. They&rsquo;re built by the people behind every match.&rdquo;</p>
            </blockquote>
        </figure>
    </main>
</div>
