<?php

namespace App\Livewire\Admin\Referrals;

use App\Livewire\Admin\Referrals\Concerns\ReferralsPage;
use App\Models\Referral;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Referral Management > History: every completed referral, searchable by either player's
 * username/mobile or the referral code used.
 */
#[Layout('components.layouts.admin')]
class History extends Component
{
    use ReferralsPage;
    use WithPagination;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $referrals = Referral::query()
            ->with(['referrer', 'referred'])
            ->when($this->search !== '', function ($query) {
                $term = "%{$this->search}%";
                $query->where('referral_code_used', 'like', $term)
                    ->orWhereHas('referrer', fn ($q) => $q->where('username', 'like', $term)->orWhere('mobile', 'like', $term))
                    ->orWhereHas('referred', fn ($q) => $q->where('username', 'like', $term)->orWhere('mobile', 'like', $term));
            })
            ->latest('id')
            ->paginate(20);

        return view('livewire.admin.referrals.history', compact('referrals'));
    }
}
