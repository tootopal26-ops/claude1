{{-- Referral Management section tabs. --}}
<nav class="-mx-1 mb-6 overflow-x-auto px-1 pb-1" aria-label="Referral Management">
    <x-ui.tabs label="Referral Management sections" class="flex-nowrap">
        @foreach (\App\Livewire\Admin\Referrals\Concerns\ReferralsPage::sections() as $section)
            <x-ui.tab :href="portal_route($section['route'])" :active="request()->routeIs('*.'.$section['route'])" class="whitespace-nowrap">{{ $section['label'] }}</x-ui.tab>
        @endforeach
    </x-ui.tabs>
</nav>
