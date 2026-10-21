<?php

namespace App\Console\Commands;

use App\Domain\Tournament\GenerateTournamentsFromTemplatesAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tournaments:generate-from-templates')]
#[Description('Generate upcoming tournament occurrences from active recurring templates')]
class GenerateTournamentsFromTemplatesCommand extends Command
{
    public function handle(GenerateTournamentsFromTemplatesAction $action): int
    {
        $result = $action->execute();

        $this->line("Generated {$result['generated']} tournament(s), skipped {$result['skipped_conflicts']} conflict(s).");

        return self::SUCCESS;
    }
}
