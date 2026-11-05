<?php

namespace App\Console\Commands;

use App\Domain\Payments\DepositService;
use App\Models\DepositOrder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The gateway webhook (DepositService::handleWebhook) is the primary way a deposit is
 * marked paid; a player polling GET /deposits/{id} (DepositController::show, also calling
 * DepositService::reconcile()) is the secondary path. Neither covers a deposit whose
 * webhook delivery was lost or delayed and whose player never returns to the app to
 * trigger a poll — this command closes that gap by reconciling every order still pending
 * past a grace window against the gateway's own check-status endpoint, through the same
 * reconcile() method those paths already use (idempotent, lockForUpdate-guarded — safe to
 * run on an order a webhook resolves in the same instant).
 */
#[Signature('payments:reconcile-pending-deposits {--minutes=10 : Only reconcile orders that have been pending at least this long}')]
#[Description('Reconcile deposit orders still pending after a grace window against the payment gateway')]
class ReconcilePendingDepositsCommand extends Command
{
    public function handle(DepositService $deposits): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));
        $resolved = 0;
        $stillPending = 0;

        DepositOrder::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($deposits, &$resolved, &$stillPending) {
                foreach ($orders as $order) {
                    if ($deposits->reconcile($order)->status === 'pending') {
                        $stillPending++;
                    } else {
                        $resolved++;
                    }
                }
            });

        if ($resolved > 0) {
            Log::info('Reconciled pending deposit orders against the gateway.', [
                'resolved' => $resolved,
                'still_pending' => $stillPending,
            ]);
        }

        $this->info("Resolved {$resolved} pending deposit order(s); {$stillPending} still pending.");

        return self::SUCCESS;
    }
}
