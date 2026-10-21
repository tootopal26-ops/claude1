<?php

namespace App\Livewire\Admin;

use App\Models\PermissionPreset;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class PresetList extends Component
{
    public function render()
    {
        $presets = PermissionPreset::withCount(['permissions', 'staffUsers'])->orderBy('name')->get();

        return view('livewire.admin.preset-list', compact('presets'));
    }
}
