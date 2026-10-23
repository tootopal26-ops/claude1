<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\SignsInToPortal;
use App\Support\Portal;
use Livewire\Component;

/**
 * Staff portal sign-in (/staff/login) — every non-super-admin account.
 */
class Login extends Component
{
    use SignsInToPortal;

    protected function portal(): string
    {
        return Portal::STAFF;
    }

    public function render()
    {
        return view('livewire.staff.login')->layout('components.layouts.auth', [
            'portal' => Portal::STAFF,
            'title' => 'Staff sign in · Mini Max Operations Hub',
        ]);
    }
}
