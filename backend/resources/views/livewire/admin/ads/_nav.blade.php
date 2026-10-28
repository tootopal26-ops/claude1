{{-- Ads & Monetization section tabs. Only the pages this account may open are listed. --}}
@php($staff = auth('staff')->user())
<nav class="-mx-1 mb-6 overflow-x-auto px-1 pb-1" aria-label="Ads & Monetization">
    <x-ui.tabs label="Ads & Monetization sections" class="flex-nowrap">
        @foreach (\App\Livewire\Admin\Ads\Concerns\AdsPage::sections() as $section)
            @if ($staff->hasPermission($section['permission']))
                <x-ui.tab :href="portal_route($section['route'])" :active="request()->routeIs('*.'.$section['route'])" class="whitespace-nowrap">{{ $section['label'] }}</x-ui.tab>
            @endif
        @endforeach
    </x-ui.tabs>
</nav>
