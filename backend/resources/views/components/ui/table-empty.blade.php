@props(['colspan', 'title', 'description' => null, 'icon' => 'info'])

<tr class="hover:bg-transparent!">
    <td colspan="{{ $colspan }}" class="p-0!">
        <x-ui.empty-state :title="$title" :description="$description" :icon="$icon">{{ $slot }}</x-ui.empty-state>
    </td>
</tr>
