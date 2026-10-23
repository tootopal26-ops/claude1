<?php

namespace App\Console\Commands\Testing;

use App\Domain\Sections\SectionUnlockService;
use App\Models\SectionUnlockPrice;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:attempt-section-unlock {user} {section}')]
#[Description('Attempt to unlock a section for a user; prints UNLOCKED, ALREADY or REJECTED:<result>')]
class AttemptSectionUnlock extends Command
{
    public function handle(SectionUnlockService $sections): int
    {
        // A second, test-only section so two different purchases can compete for one balance.
        config(['minimax.unlockable_sections.arena' => ['title' => 'Arena', 'description' => 'Test-only section.', 'icon' => 'tournaments']]);

        $key = (string) $this->argument('section');
        $expected = SectionUnlockPrice::where('section_key', $key)->value('price_coins');

        $outcome = $sections->unlock(User::findOrFail($this->argument('user')), $key, $expected);

        $this->line(match ($outcome->result) {
            'success' => 'UNLOCKED',
            'already_unlocked' => 'ALREADY',
            default => 'REJECTED:'.$outcome->result,
        });

        return $outcome->succeeded() ? self::SUCCESS : self::FAILURE;
    }
}
