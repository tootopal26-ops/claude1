<?php

namespace App\Livewire\Admin;

use App\Models\NotificationBatch;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class NotificationHistory extends Component
{
    use WithPagination;

    public function render()
    {
        $batches = NotificationBatch::with('createdByStaff')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.admin.notification-history', compact('batches'));
    }
}
