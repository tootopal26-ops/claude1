<?php

namespace App\Livewire\Admin;

use App\Domain\Wallet\InsufficientWalletBalanceException;
use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class UserShow extends Component
{
    public User $user;

    public string $adjustDirection = 'add';

    public string $adjustAmount = '';

    public string $adjustBalance = 'deposit';

    public string $adjustReason = '';

    public ?string $adjustError = null;

    public function mount(User $user): void
    {
        $this->user = $user;
    }

    public function toggleBan(): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('users.ban'), 403);

        $this->user->is_banned = ! $this->user->is_banned;
        $this->user->save();

        AuditLog::record(
            $this->user->is_banned ? 'user.banned' : 'user.unbanned',
            Auth::guard('staff')->user(),
            $this->user,
        );

        session()->flash('status', $this->user->is_banned
            ? "{$this->user->username} has been banned."
            : "{$this->user->username} has been unbanned.");
    }

    public function adjustWallet(WalletService $wallet): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('wallet.manage'), 403);

        $this->adjustError = null;

        $this->validate([
            'adjustAmount' => ['required', 'numeric', 'gt:0'],
            'adjustBalance' => ['required', 'in:deposit,winnings'],
            'adjustReason' => ['required', 'string', 'max:255'],
        ]);

        $signedAmount = $this->adjustDirection === 'subtract'
            ? bcmul($this->adjustAmount, '-1', 2)
            : bcadd($this->adjustAmount, '0', 2);

        try {
            $wallet->manualAdjust(
                $this->user,
                $signedAmount,
                $this->adjustBalance,
                $this->adjustReason,
                Auth::guard('staff')->user(),
                (string) Str::uuid(),
            );
        } catch (InsufficientWalletBalanceException $exception) {
            $this->adjustError = $exception->getMessage();

            return;
        }

        $this->reset(['adjustAmount', 'adjustReason']);
        session()->flash('status', 'Wallet adjustment applied.');
    }

    public function render()
    {
        $this->user->loadMissing('wallet');

        $ledgerEntries = $this->user->wallet
            ? $this->user->wallet->ledgerEntries()->orderByDesc('created_at')->limit(20)->get()
            : collect();

        $tournamentEntries = $this->user->tournamentEntries()->with('tournament')->orderByDesc('created_at')->limit(10)->get();

        return view('livewire.admin.user-show', compact('ledgerEntries', 'tournamentEntries'));
    }
}
