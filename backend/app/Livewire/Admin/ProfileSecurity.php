<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\StaffUser;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Any signed-in staff/admin account changing its own password. Deliberately not
 * permission-gated (like /appearance/mode) — every account, however narrow its preset, must
 * be able to secure its own login.
 */
#[Layout('components.layouts.admin')]
class ProfileSecurity extends Component
{
    public string $currentPassword = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function save(): void
    {
        /** @var StaffUser $staff */
        $staff = Auth::guard('staff')->user();

        $this->validate([
            'currentPassword' => ['required', 'current_password:staff'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:currentPassword'],
        ], attributes: ['currentPassword' => 'current password', 'password' => 'new password']);

        $staff->update(['password' => $this->password]);

        AuditLog::record('staff.password_changed', $staff, $staff);

        $this->reset(['currentPassword', 'password', 'password_confirmation']);

        session()->flash('status', 'Your password was changed.');
    }

    public function render()
    {
        return view('livewire.admin.profile-security');
    }
}
