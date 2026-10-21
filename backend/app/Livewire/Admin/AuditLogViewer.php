<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class AuditLogViewer extends Component
{
    use WithPagination;

    public string $action = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $logs = AuditLog::query()
            ->with('actor')
            ->when($this->action !== '', fn ($query) => $query->where('action', $this->action))
            ->when($this->dateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->dateTo))
            ->orderByDesc('created_at')
            ->paginate(25);

        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action');

        return view('livewire.admin.audit-log-viewer', compact('logs', 'actions'));
    }
}
