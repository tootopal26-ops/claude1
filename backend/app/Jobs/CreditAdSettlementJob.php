<?php

namespace App\Jobs;

use App\Domain\Ads\AdSettlementService;
use App\Models\AdSettlementBatch;
use App\Models\StaffUser;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Credits one Ads Earning settlement in the background. Unique per batch, and the
 * crediting itself is idempotent, so a retried or duplicated job never pays twice.
 */
class CreditAdSettlementJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $batchId, public ?int $actorId = null) {}

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    public function handle(AdSettlementService $settlements): void
    {
        $batch = AdSettlementBatch::find($this->batchId);

        if ($batch === null || ! in_array($batch->status, ['calculated', 'crediting', 'failed'], true)) {
            return;
        }

        $settlements->credit($batch, $this->actorId ? StaffUser::find($this->actorId) : null);
    }
}
