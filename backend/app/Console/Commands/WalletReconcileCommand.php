<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The cached balance columns on `wallets` exist only for fast reads —
 * wallet_ledger_entries is the actual source of truth. This recomputes
 * each wallet's balances by summing its ledger and compares that against
 * the cached columns; any mismatch means a bug wrote to a wallet without
 * going through WalletService; see docs on WalletService before adding
 * any new way to touch a balance.
 */
#[Signature('wallet:reconcile {--dry-run : Report mismatches without correcting them}')]
#[Description('Recompute each wallet\'s balances from its ledger and correct any drift from the cached columns')]
class WalletReconcileCommand extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $mismatches = 0;

        Wallet::query()->orderBy('id')->chunkById(200, function ($wallets) use (&$mismatches, $dryRun) {
            foreach ($wallets as $wallet) {
                $mismatches += $this->reconcileOne($wallet, $dryRun);
            }
        });

        if ($mismatches === 0) {
            $this->info('All wallets reconcile cleanly against their ledgers.');
        } else {
            $this->warn("{$mismatches} wallet(s) had drift from their ledger".($dryRun ? ' (not corrected — dry run).' : ' and were corrected.'));
        }

        return self::SUCCESS;
    }

    private function reconcileOne(Wallet $wallet, bool $dryRun): int
    {
        $sums = DB::table('wallet_ledger_entries')
            ->where('wallet_id', $wallet->id)
            ->selectRaw('balance, SUM(amount) as total')
            ->groupBy('balance')
            ->pluck('total', 'balance');

        $ledgerDeposit = (string) ($sums['deposit'] ?? '0.00');
        $ledgerWinnings = (string) ($sums['winnings'] ?? '0.00');

        $depositDrift = bccomp($ledgerDeposit, (string) $wallet->deposit_balance, 2) !== 0;
        $winningsDrift = bccomp($ledgerWinnings, (string) $wallet->eligible_winnings_balance, 2) !== 0;

        if (! $depositDrift && ! $winningsDrift) {
            return 0;
        }

        Log::warning('Wallet balance drift detected during reconciliation', [
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'cached_deposit' => (string) $wallet->deposit_balance,
            'ledger_deposit' => $ledgerDeposit,
            'cached_winnings' => (string) $wallet->eligible_winnings_balance,
            'ledger_winnings' => $ledgerWinnings,
            'corrected' => ! $dryRun,
        ]);

        $this->line("Wallet #{$wallet->id} (user #{$wallet->user_id}): deposit cached={$wallet->deposit_balance} ledger={$ledgerDeposit}, winnings cached={$wallet->eligible_winnings_balance} ledger={$ledgerWinnings}");

        if (! $dryRun) {
            $wallet->forceFill([
                'deposit_balance' => $ledgerDeposit,
                'eligible_winnings_balance' => $ledgerWinnings,
            ])->save();
        }

        return 1;
    }
}
