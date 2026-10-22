<div>
    <h1 class="mb-6 text-2xl font-bold text-violet-200">Welcome</h1>

    @if ($sections->isEmpty())
        <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6 text-violet-300">
            Your account doesn't have access to any section yet. Contact an administrator to have a permission preset assigned.
        </div>
    @else
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
            @foreach ($sections as $section)
                <a href="{{ route($section['route']) }}" class="rounded-2xl border border-violet-900/40 bg-white/5 p-4 text-center text-violet-200 hover:border-violet-700 hover:text-white">
                    {{ $section['label'] }}
                </a>
            @endforeach
        </div>
    @endif
</div>
