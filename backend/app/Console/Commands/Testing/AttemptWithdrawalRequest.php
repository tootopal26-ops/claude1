<?php

namespace App\Console\Commands\Testing;

use App\Domain\Withdrawal\WithdrawalService;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('app:attempt-withdrawal-request {user} {amount}')]
#[Description('Attempt to request a withdrawal; prints REQUESTED or REJECTED')]
class AttemptWithdrawalRequest extends Command
{
    public function handle(WithdrawalService $withdrawals): int
    {
        $user = User::findOrFail($this->argument('user'));

        try {
            $withdrawals->request($user, $this->argument('amount'), 'concurrency-test@upi');
            $this->line('REQUESTED');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->line('REJECTED: '.collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        }
    }
}
