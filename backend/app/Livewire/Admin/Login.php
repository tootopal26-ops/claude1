<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class Login extends Component
{
    public string $username = '';

    public string $password = '';

    public function login(): void
    {
        $this->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('staff')->attempt(['username' => $this->username, 'password' => $this->password], remember: true)) {
            throw ValidationException::withMessages([
                'username' => 'Those credentials do not match an active staff account.',
            ]);
        }

        if (! Auth::guard('staff')->user()->is_active) {
            Auth::guard('staff')->logout();

            throw ValidationException::withMessages([
                'username' => 'This staff account has been deactivated.',
            ]);
        }

        session()->regenerate();
        session(['staff_security_version' => Auth::guard('staff')->user()->security_version]);

        $this->redirectRoute('admin.home', navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.login');
    }
}
