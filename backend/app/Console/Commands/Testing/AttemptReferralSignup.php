<?php

namespace App\Console\Commands\Testing;

use App\Domain\Referrals\ReferralService;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('app:attempt-referral-signup {code} {index}')]
#[Description('Create a new player and attempt to apply a referral code; prints REFERRED or REJECTED:<message>')]
class AttemptReferralSignup extends Command
{
    public function handle(ReferralService $referrals): int
    {
        $index = (int) $this->argument('index');

        $newUser = User::create([
            'username' => "racer{$index}",
            'mobile' => '9'.str_pad((string) $index, 9, '0', STR_PAD_LEFT),
            'email' => "racer{$index}@concurrency.test",
            'date_of_birth' => now()->subYears(20)->toDateString(),
            'state' => 'Delhi',
            'password' => 'password123',
        ]);

        try {
            $referral = $referrals->applyReferral($newUser, $this->argument('code'));
            $this->line($referral !== null ? 'REFERRED' : 'NOOP');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->line('REJECTED:'.collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        }
    }
}
