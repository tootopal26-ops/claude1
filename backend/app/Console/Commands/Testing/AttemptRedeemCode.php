<?php

namespace App\Console\Commands\Testing;

use App\Domain\Redeem\RedeemCodeService;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:attempt-redeem-code {user} {code}')]
#[Description('Attempt to redeem a code for a user; prints REDEEMED or REJECTED:<result>')]
class AttemptRedeemCode extends Command
{
    public function handle(RedeemCodeService $redeem): int
    {
        $outcome = $redeem->redeem(User::findOrFail($this->argument('user')), $this->argument('code'));

        $this->line($outcome->succeeded() ? 'REDEEMED' : 'REJECTED:'.$outcome->result);

        return $outcome->succeeded() ? self::SUCCESS : self::FAILURE;
    }
}
