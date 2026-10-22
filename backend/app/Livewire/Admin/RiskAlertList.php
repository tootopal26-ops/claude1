<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\RiskAlert;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class RiskAlertList extends Component
{
    use WithPagination;

    public string $type = '';

    public string $riskLevel = '';

    public string $status = 'open';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function markReviewed(int $alertId): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('audit_logs.view'), 403);

        $alert = RiskAlert::findOrFail($alertId);

        if ($alert->isOpen()) {
            $alert->reviewed_at = now();
            $alert->reviewed_by_staff_id = Auth::guard('staff')->id();
            $alert->save();

            AuditLog::record('risk_alert.reviewed', Auth::guard('staff')->user(), $alert, ['type' => $alert->type]);
        }
    }

    public function render()
    {
        $alerts = RiskAlert::query()
            ->with(['targetUser', 'actorStaff'])
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->when($this->riskLevel !== '', fn ($query) => $query->where('risk_level', $this->riskLevel))
            ->when($this->status === 'open', fn ($query) => $query->whereNull('reviewed_at'))
            ->when($this->status === 'reviewed', fn ($query) => $query->whereNotNull('reviewed_at'))
            ->orderByDesc('created_at')
            ->paginate(25);

        $types = RiskAlert::query()->distinct()->orderBy('type')->pluck('type');

        return view('livewire.admin.risk-alert-list', compact('alerts', 'types'));
    }
}
