<?php

namespace App\Livewire\Admin;

use App\Models\StaffUser;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class StaffList extends Component
{
    public function render()
    {
        $staffMembers = StaffUser::with('permissionPreset')->orderBy('display_name')->get();

        return view('livewire.admin.staff-list', compact('staffMembers'));
    }
}
