<?php

namespace App\Livewire\Admin;

use App\Models\PermissionPreset;
use App\Models\StaffUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class StaffForm extends Component
{
    public ?StaffUser $staffUser = null;

    public string $display_name = '';

    public string $username = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?int $permission_preset_id = null;

    public bool $is_active = true;

    public bool $is_super_admin = false;

    public bool $actingStaffIsSuperAdmin = false;

    public function mount(?StaffUser $staffUser = null): void
    {
        $this->staffUser = $staffUser;
        $this->actingStaffIsSuperAdmin = (bool) Auth::guard('staff')->user()?->is_super_admin;

        if ($staffUser !== null) {
            $this->display_name = $staffUser->display_name;
            $this->username = $staffUser->username;
            $this->permission_preset_id = $staffUser->permission_preset_id;
            $this->is_active = $staffUser->is_active;
            $this->is_super_admin = $staffUser->is_super_admin;
        }
    }

    public function save(): void
    {
        $this->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'username' => $this->staffUser
                ? ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('staff_users', 'username')->ignore($this->staffUser->id)]
                : ['required', 'string', 'max:255', 'alpha_dash', 'unique:staff_users,username'],
            'password' => $this->staffUser ? ['nullable', 'string', 'min:8', 'confirmed'] : ['required', 'string', 'min:8', 'confirmed'],
            'permission_preset_id' => ['nullable', 'exists:permission_presets,id'],
            'is_active' => ['boolean'],
        ]);

        $staff = $this->staffUser ?? new StaffUser;

        $securityRelevantChange = $staff->exists && (
            $staff->permission_preset_id !== $this->permission_preset_id
            || $staff->is_active !== $this->is_active
            || ($this->actingStaffIsSuperAdmin && $staff->is_super_admin !== $this->is_super_admin)
            || $this->password !== ''
        );

        $staff->display_name = $this->display_name;
        $staff->username = $this->username;
        $staff->permission_preset_id = $this->permission_preset_id;
        $staff->is_active = $this->is_active;

        // Only an existing super admin can grant or revoke super-admin status —
        // a staff.manage holder without that flag themselves can't use this form
        // to escalate anyone (including themselves) to it.
        if ($this->actingStaffIsSuperAdmin) {
            $staff->is_super_admin = $this->is_super_admin;
        }

        if ($this->password !== '') {
            $staff->password = $this->password;
        }

        $staff->save();

        if ($securityRelevantChange) {
            $staff->invalidateActiveSessions();
        }

        session()->flash('status', "Staff account \"{$staff->display_name}\" saved.");

        $this->redirectRoute('admin.staff.index', navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.staff-form', [
            'presets' => PermissionPreset::orderBy('name')->get(),
        ]);
    }
}
