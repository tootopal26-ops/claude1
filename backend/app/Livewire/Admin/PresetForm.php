<?php

namespace App\Livewire\Admin;

use App\Models\Permission;
use App\Models\PermissionPreset;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class PresetForm extends Component
{
    public ?PermissionPreset $preset = null;

    public string $name = '';

    public string $description = '';

    /** @var array<int, int> */
    public array $selectedPermissionIds = [];

    public function mount(?PermissionPreset $preset = null): void
    {
        $this->preset = $preset;

        if ($preset !== null) {
            $this->name = $preset->name;
            $this->description = (string) $preset->description;
            $this->selectedPermissionIds = $preset->permissions()->pluck('permissions.id')->all();
        }
    }

    public function save(): void
    {
        $this->validate([
            'name' => $this->preset
                ? ['required', 'string', 'max:255', 'unique:permission_presets,name,'.$this->preset->id]
                : ['required', 'string', 'max:255', 'unique:permission_presets,name'],
            'description' => ['nullable', 'string', 'max:500'],
            'selectedPermissionIds' => ['array'],
            'selectedPermissionIds.*' => ['exists:permissions,id'],
        ]);

        $preset = $this->preset ?? new PermissionPreset;
        $preset->name = $this->name;
        $preset->description = $this->description ?: null;
        $preset->save();

        $preset->permissions()->sync($this->selectedPermissionIds);

        session()->flash('status', "Preset \"{$preset->name}\" saved.");

        $this->redirectRoute('admin.presets.index', navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.preset-form', [
            'permissionsByGroup' => Permission::orderBy('group')->orderBy('label')->get()->groupBy('group'),
        ]);
    }
}
