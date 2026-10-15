<?php

declare(strict_types=1);

namespace App\Services;

/**
 * What a scheduled job is handed by Jobs::run: its time budget, whether this is a dry run, and a place to leave a short summary.
 * Jobs are written to work in small batches and to look at timeLeft() between them, so a run always ends inside its budget.
 */
final class JobContext
{
    /** @var array<string,mixed> */
    private array $summary = [];
    private bool $stopped = false;

    public function __construct(public readonly float $deadline, public readonly bool $dryRun = false)
    {
    }

    /** Seconds left in this run's budget (never negative). */
    public function timeLeft(): float
    {
        return max(0.0, $this->deadline - microtime(true));
    }

    /** True once the budget is used up; the job should stop starting new work. */
    public function expired(): bool
    {
        if ($this->timeLeft() <= 0.0) {
            $this->stopped = true;
        }
        return $this->stopped;
    }

    public function stoppedEarly(): bool
    {
        return $this->stopped;
    }

    /** Add a counter or a short note to the run's result (kept small: it is stored and shown in the admin). */
    public function note(string $key, mixed $value): void
    {
        $this->summary[$key] = $value;
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        return $this->summary;
    }
}
