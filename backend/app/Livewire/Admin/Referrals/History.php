<?php

namespace App\Livewire\Admin\Referrals;

use App\Livewire\Admin\Referrals\Concerns\ReferralsPage;
use App\Models\Referral;
use App\Support\MobileNumber;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Referral Management > History: every completed referral, searchable by either player's
 * username/mobile/email or the referral code used.
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
        $term = trim($this->search);
        $normalizedMobile = MobileNumber::isValid($term) ? MobileNumber::normalize($term) : null;

        $referrals = Referral::query()
            ->with(['referrer', 'referred'])
            ->when($term !== '', function ($query) use ($term, $normalizedMobile) {
                $query->where('referral_code_used', 'like', "%{$term}%")
                    ->orWhereHas('referrer', fn (Builder $q) => $this->matchPlayer($q, $term, $normalizedMobile))
                    ->orWhereHas('referred', fn (Builder $q) => $this->matchPlayer($q, $term, $normalizedMobile));
            })
            ->latest('id')
            ->paginate(20);

        return view('livewire.admin.referrals.history', compact('referrals'));
    }

    private function matchPlayer(Builder $query, string $term, ?string $normalizedMobile): void
    {
        $query->where('username', 'like', "%{$term}%")
            ->orWhere('email', 'like', "%{$term}%")
            ->orWhere('mobile', 'like', "%{$term}%");

        if ($normalizedMobile !== null) {
            $query->orWhere('mobile', 'like', "%{$normalizedMobile}%");
        }
    }
}
