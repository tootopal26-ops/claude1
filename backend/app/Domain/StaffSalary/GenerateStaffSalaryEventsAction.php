<?php

namespace App\Domain\StaffSalary;

use App\Models\StaffSalaryEvent;
use App\Models\StaffUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns audit_logs rows into staff_salary_events: every staff-attributed
 * action (actor is a StaffUser) that matches an active rule for that
 * staff member + action type becomes exactly one payable event, keyed to
 * the action's own subject record so the same underlying business event
 * (e.g. "this specific withdrawal's approval") can never be paid twice.
 *
 * The amount is frozen from the rule at generation time onto the event
 * itself — a rule changed or deactivated later never retroactively
 * changes an already-generated event.
 *
 * The query below already excludes rows that already have a matching
 * event (a LEFT JOIN anti-pattern), so this only does real work for
 * genuinely new audit log rows; the try/catch below is just a safety net
 * against a concurrent run racing on the same row.
 */
class GenerateStaffSalaryEventsAction
{
    public function execute(): int
    {
        $staffMorphClass = (new StaffUser)->getMorphClass();

        $pending = DB::table('audit_logs')
            ->join('staff_salary_rules', function ($join) {
                $join->on('staff_salary_rules.staff_user_id', '=', 'audit_logs.actor_id')
                    ->on('staff_salary_rules.action_type', '=', 'audit_logs.action');
            })
            ->leftJoin('staff_salary_events', function ($join) {
                $join->on('staff_salary_events.action_type', '=', 'audit_logs.action')
                    ->on('staff_salary_events.source_type', '=', 'audit_logs.subject_type')
                    ->on('staff_salary_events.source_id', '=', 'audit_logs.subject_id');
            })
            ->where('audit_logs.actor_type', $staffMorphClass)
            ->where('staff_salary_rules.is_active', true)
            ->whereNotNull('audit_logs.subject_type')
            ->whereNotNull('audit_logs.subject_id')
            ->whereNull('staff_salary_events.id')
            ->select([
                'audit_logs.actor_id as staff_user_id',
                'staff_salary_rules.id as staff_salary_rule_id',
                'audit_logs.action as action_type',
                'audit_logs.subject_type as source_type',
                'audit_logs.subject_id as source_id',
                'staff_salary_rules.amount_per_action as amount',
                'audit_logs.created_at as occurred_at',
            ])
            ->get();

        $created = 0;

        foreach ($pending as $row) {
            try {
                StaffSalaryEvent::create([
                    'staff_user_id' => $row->staff_user_id,
                    'staff_salary_rule_id' => $row->staff_salary_rule_id,
                    'action_type' => $row->action_type,
                    'source_type' => $row->source_type,
                    'source_id' => $row->source_id,
                    'amount' => $row->amount,
                    'occurred_at' => $row->occurred_at,
                ]);
                $created++;
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        return $created;
    }
}
