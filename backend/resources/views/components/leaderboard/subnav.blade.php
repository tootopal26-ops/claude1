@props(['current' => 'rankings'])

{{-- Sections of Leaderboard Management. Plain links, so leaving a form with unsaved changes triggers the browser's warning. --}}
<x-ui.tabs label="Leaderboard management" class="mb-6">
    <x-ui.tab :href="portal_route('leaderboards.index')" :active="$current === 'rankings'">Rankings</x-ui.tab>
    <x-ui.tab :href="portal_route('leaderboards.prizes')" :active="$current === 'prizes'">Prize rules</x-ui.tab>
    <x-ui.tab :href="portal_route('leaderboards.settlement')" :active="$current === 'settlement'">Settlement</x-ui.tab>
    <x-ui.tab :href="portal_route('leaderboards.banners.index')" :active="$current === 'banners'">Banners</x-ui.tab>
</x-ui.tabs>
