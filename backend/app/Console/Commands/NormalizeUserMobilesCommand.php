<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time cleanup for accounts created before mobile numbers were
 * normalised to plain 10 digits and emails were case-folded. Never
 * merges or deletes an account: a row is only ever changed when its own
 * normalised value is unambiguous and doesn't collide with any other
 * user's (current or about-to-be-normalised) value. Everything else is
 * left exactly as it was and printed in a report instead.
 *
 * Every user's target mobile/email is computed first, over the whole
 * table, before anything is saved — not chunk by chunk — so two users
 * who would only collide AFTER normalising (neither collides today) are
 * still caught, rather than one of them slipping through a unique
 * constraint race against the other's not-yet-saved row.
 */
#[Signature('users:normalize-mobiles {--dry-run : Report what would change without saving anything}')]
#[Description('Normalise existing users\' mobile numbers to 10 digits and fold emails to lowercase, without ever creating a collision')]
class NormalizeUserMobilesCommand extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $users = User::query()->orderBy('id')->get(['id', 'username', 'mobile', 'email']);

        $plannedMobile = [];
        $plannedEmail = [];
        $digitOnlyUsernames = [];

        foreach ($users as $user) {
            if ($user->username !== '' && ctype_digit($user->username)) {
                $digitOnlyUsernames[] = $user;
            }

            $plannedMobile[$user->id] = MobileNumber::normalize((string) $user->mobile);
            $plannedEmail[$user->id] = mb_strtolower(trim((string) $user->email));
        }

        // How many users would hold each target value after this run — unchanged
        // peers included — so two users converging on the same new value is
        // caught even when neither collides with the other's CURRENT value.
        $mobileTargetCounts = array_count_values($plannedMobile);
        $emailTargetCounts = array_count_values($plannedEmail);

        $changed = 0;
        $skipped = 0;
        $conflicts = [];
        $toSave = [];

        foreach ($users as $user) {
            $newMobile = $plannedMobile[$user->id];
            $newEmail = $plannedEmail[$user->id];

            $mobileChanged = $newMobile !== $user->mobile;
            $emailChanged = $newEmail !== $user->email;

            // Checked unconditionally, not only when mobileChanged: a mobile that
            // is already invalid and happens to equal its own "normalised" form
            // (so this run wouldn't otherwise touch it) is still worth a report.
            if (! MobileNumber::isValid($newMobile)) {
                $conflicts[] = [$user->id, $user->username, $user->mobile, 'Mobile does not normalise to exactly 10 digits'];
                $skipped++;

                continue;
            }

            if (! $mobileChanged && ! $emailChanged) {
                continue;
            }

            if ($mobileChanged && $mobileTargetCounts[$newMobile] > 1) {
                $conflicts[] = [$user->id, $user->username, $user->mobile, "Normalises to \"{$newMobile}\", which collides with another user"];
                $skipped++;

                continue;
            }

            if ($emailChanged && $emailTargetCounts[$newEmail] > 1) {
                $conflicts[] = [$user->id, $user->username, $user->email, "Normalises to \"{$newEmail}\", which collides with another user"];
                $skipped++;

                continue;
            }

            $toSave[] = [$user, $newMobile, $newEmail];
            $changed++;
        }

        if (! $dryRun) {
            foreach (array_chunk($toSave, 200) as $batch) {
                DB::transaction(function () use ($batch) {
                    foreach ($batch as [$user, $mobile, $email]) {
                        $user->forceFill(['mobile' => $mobile, 'email' => $email])->save();
                    }
                });
            }
        }

        $this->info($dryRun ? 'Dry run — no changes were saved.' : 'Normalisation applied.');
        $this->line("Changed: {$changed}");
        $this->line("Skipped (conflicts): {$skipped}");

        if ($conflicts !== []) {
            $this->newLine();
            $this->warn('Left unchanged — resolve these by hand:');
            $this->table(['ID', 'Username', 'Old value', 'Reason'], $conflicts);
        }

        if ($digitOnlyUsernames !== []) {
            $this->newLine();
            $this->warn('Usernames that are digits only (ambiguous at sign-in) — not renamed automatically:');
            $this->table(['ID', 'Username'], array_map(fn (User $u) => [$u->id, $u->username], $digitOnlyUsernames));
        }

        return self::SUCCESS;
    }
}
