<?php

namespace App\Console\Commands;

use App\Domain\StaffSalary\GenerateStaffSalaryEventsAction;
use App\Domain\StaffSalary\GenerateStaffSalarySettlementsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('staff-salary:maintain')]
#[Description('Generate new salary events from audit logs, then group unsettled events into weekly settlements')]
class MaintainStaffSalariesCommand extends Command
{
    public function handle(
        GenerateStaffSalaryEventsAction $generateEvents,
        GenerateStaffSalarySettlementsAction $generateSettlements,
    ): int {
        $eventsCreated = $generateEvents->execute();
        $settlementsTouched = $generateSettlements->execute();

        $this->line("{$eventsCreated} salary event(s) generated, {$settlementsTouched} settlement(s) updated.");

        return self::SUCCESS;
    }
}
