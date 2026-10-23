<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\SignsInToPortal;
use App\Support\Portal;
use Livewire\Component;

/**
 * Admin portal sign-in (/admin/login) — super-admin accounts only.
 */
class Login extends Component
{
    use SignsInToPortal;

    protected function portal(): string
    {
        return Portal::ADMIN;
    }

    public function render()
    {
        return view('livewire.admin.login')->layout('components.layouts.auth', [
            'portal' => Portal::ADMIN,
            'title' => 'Admin sign in · Mini Max Control Center',
        ]);
    }
}
