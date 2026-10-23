<?php

namespace App\Livewire\Admin;

use App\Models\RedeemAttempt;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every redeem attempt — successful or not — with the player's username only
 * (no mobile number or wallet detail). Read-only.
 */
#[Layout('components.layouts.admin')]
class RedeemHistory extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'result')]
    public string $result = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingResult(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('redeem_codes.manage'), 403);

        $attempts = RedeemAttempt::query()
            ->with(['user:id,username', 'campaign:id,name,code,reward_coins'])
            ->when($this->result === 'success', fn ($q) => $q->where('result', 'success'))
            ->when($this->result === 'failed', fn ($q) => $q->where('result', '!=', 'success'))
            ->when(trim($this->search) !== '', function ($query) {
                $term = trim($this->search);
                $query->where(fn ($q) => $q
                    ->where('submitted_code', strtoupper($term))
                    ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$term}%"))
                    ->orWhereHas('campaign', fn ($c) => $c->where('name', 'like', "%{$term}%")));
            })
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.admin.redeem-history', ['attempts' => $attempts]);
    }
}
