<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Support\Portal;
use Illuminate\Support\Facades\Auth;
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
        abort_unless(Auth::guard('staff')->user()->hasPermission('staff.manage'), 403);

        $this->validate([
            'name' => $this->preset
                ? ['required', 'string', 'max:255', 'unique:permission_presets,name,'.$this->preset->id]
                : ['required', 'string', 'max:255', 'unique:permission_presets,name'],
            'description' => ['nullable', 'string', 'max:500'],
            'selectedPermissionIds' => ['array'],
            'selectedPermissionIds.*' => ['exists:permissions,id'],
        ]);

        $wasExisting = $this->preset !== null;
        $preset = $this->preset ?? new PermissionPreset;
        $preset->name = $this->name;
        $preset->description = $this->description ?: null;
        $preset->save();

        $preset->permissions()->sync($this->selectedPermissionIds);

        AuditLog::record($wasExisting ? 'permission_preset.updated' : 'permission_preset.created', Auth::guard('staff')->user(), $preset, [
            'permission_ids' => $this->selectedPermissionIds,
        ]);

        session()->flash('status', "Preset \"{$preset->name}\" saved.");

        $this->redirectRoute(Portal::routeName('presets.index'), navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.preset-form', [
            'permissionsByGroup' => Permission::orderBy('group')->orderBy('label')->get()->groupBy('group'),
        ]);
    }
}
