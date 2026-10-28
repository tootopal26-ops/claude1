{{-- Staff portal sign-in: a lighter, centered "operations hub" composition over a bracket-and-schedule motif. --}}
<div class="mm-portal mm-portal--staff relative isolate min-h-dvh overflow-hidden bg-app">
    {{-- Background: a soft wash plus decorative bracket geometry and schedule rows. Purely ornamental — no data. --}}
    <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
        <div class="absolute inset-0" style="background: radial-gradient(55% 45% at 50% -5%, color-mix(in oklab, var(--mm-brand) 13%, transparent), transparent 72%), radial-gradient(40% 35% at 0% 100%, color-mix(in oklab, var(--mm-brand) 7%, transparent), transparent 75%);"></div>

        <x-portal.bracket-motif />

        {{-- Schedule rows along the bottom edge --}}
        <div class="absolute inset-x-0 bottom-0 hidden h-40 items-end justify-center gap-3 px-10 opacity-[0.5] sm:flex" style="-webkit-mask-image: linear-gradient(to top, #000, transparent); mask-image: linear-gradient(to top, #000, transparent);">
            @foreach ([[36, 70], [52, 40], [28, 88], [60, 52], [44, 64], [72, 34], [30, 80], [56, 46], [40, 74]] as [$h, $w])
                <div class="flex flex-1 flex-col justify-end gap-2" style="max-width: 9rem;">
                    <div class="h-1.5 rounded-full bg-surface-3" style="width: {{ $w }}%"></div>
                    <div class="rounded-md border border-line bg-surface-2/60" style="height: {{ $h * 2 }}px"></div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="absolute right-4 top-4 z-10 sm:right-6 sm:top-6">
        <x-ui.mode-toggle />
    </div>

    <main id="main" class="mx-auto flex min-h-dvh w-full max-w-2xl flex-col items-center px-5 py-8 sm:py-12">
        <x-portal.brand :size="42" tag="Operations" class="mm-rise" />

        <header class="mt-10 text-center sm:mt-14">
            <p class="mm-eyebrow mm-rise" style="--mm-delay: 80ms">MINI MAX · OPERATIONS</p>
            <h1 class="mm-rise mt-4 text-balance text-[2.25rem] font-semibold leading-[1.08] tracking-tight text-fg sm:text-5xl" style="--mm-delay: 140ms">Every Match. Every Detail.</h1>
            <p class="mm-rise mx-auto mt-4 max-w-md text-base leading-relaxed text-fg-2" style="--mm-delay: 220ms">Keep tournaments moving, support players, and make every competition run smoothly.</p>
        </header>

        <section class="mt-9 w-full max-w-md" aria-labelledby="signin-title">
            <div class="mm-glass mm-rise rounded-2xl p-6 sm:p-8" style="--mm-delay: 260ms">
                <p class="inline-flex items-center gap-2 rounded-full border border-line-strong bg-surface-2 px-3 py-1 text-[0.6875rem] font-semibold uppercase tracking-[0.2em] text-fg-2">
                    <x-icon name="users" :size="12" class="text-brand-text" /> STAFF MEMBER ACCESS
                </p>
                <h2 id="signin-title" class="mt-5 text-xl font-semibold tracking-tight text-fg">Sign in</h2>
                <p class="mt-1 text-sm text-fg-2">Use your staff credentials to open your workspace.</p>

                <x-portal.form-alert class="mt-5" />

                <form wire:submit="login" class="mt-5 space-y-5" novalidate>
                    <fieldset wire:loading.attr="disabled" wire:target="login" class="grid min-w-0 gap-5">
                        <div x-data x-init="if (window.matchMedia('(min-width: 1024px)').matches) $el.querySelector('input').focus()">
                            <x-ui.input label="Username" wire:model="username" type="text" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" inputmode="text" enterkeyhint="next" required class="h-11" />
                        </div>

                        <x-portal.password-field />
                    </fieldset>

                    <x-ui.button type="submit" size="lg" class="w-full" loading="login">Open Operations Hub</x-ui.button>
                </form>
            </div>
        </section>

        <figure class="mm-rise mt-9 max-w-md text-center" style="--mm-delay: 320ms">
            <blockquote>
                <p class="text-base italic leading-relaxed text-fg-2">&ldquo;Champions make the plays. Great teams make the moments possible.&rdquo;</p>
            </blockquote>
        </figure>

        <div class="mm-rise mt-auto pt-10 text-center text-xs text-fg-3" style="--mm-delay: 380ms">
            <p>Administrator? <a href="{{ route('admin.login') }}" class="font-medium text-brand-text underline-offset-4 hover:underline">Open the Admin portal</a></p>
            <p class="mt-3">Authorized Mini Max staff only · Every action matters</p>
        </div>
    </main>
</div>
