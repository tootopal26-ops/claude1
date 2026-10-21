<?php

namespace App\Domain\Tournament;

use App\Domain\Notifications\NotificationService;
use App\Models\AuditLog;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Releases the room ID/password for a tournament, exactly once. Releasing
 * credentials also closes joining immediately (status -> ongoing), ahead of
 * the scheduled join-window cutoff, and overrides the min-players
 * auto-cancel check: staff releasing credentials is a deliberate decision
 * to run the match regardless of headcount (see
 * AutoCancelUnderfilledTournamentsCommand).
 *
 * Credentials themselves are never put in the notification payload — only
 * a "they're live" ping with a deep link. Delivering the actual room_id/
 * room_password is the credentials API endpoint's job, which checks the
 * requesting player has a confirmed entry before returning anything.
 */
class ReleaseCredentialsAction
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @throws ValidationException
     */
    public function execute(Tournament $tournament, string $roomId, string $roomPassword, StaffUser $actor): TournamentCredential
    {
        $credential = DB::transaction(function () use ($tournament, $roomId, $roomPassword, $actor) {
            /** @var Tournament $locked */
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->credentials_released_at !== null) {
                throw ValidationException::withMessages([
                    'tournament' => 'Credentials have already been released for this tournament.',
                ]);
            }

            if (! in_array($locked->status, ['upcoming', 'ongoing'], true)) {
                throw ValidationException::withMessages([
                    'tournament' => 'Credentials can only be released for an upcoming or ongoing tournament.',
                ]);
            }

            $credential = TournamentCredential::create([
                'tournament_id' => $locked->id,
                'room_id' => $roomId,
                'room_password' => $roomPassword,
                'released_by_staff_id' => $actor->id,
            ]);

            $locked->credentials_released_at = now();
            $locked->status = 'ongoing';
            $locked->save();

            AuditLog::record('tournament.credentials_released', $actor, $locked);

            return $credential;
        });

        $participants = $tournament->entries()->where('status', 'confirmed')->with('user')->get()->pluck('user');

        if ($participants->isNotEmpty()) {
            $this->notifications->notify(
                recipients: $participants,
                type: 'tournament.credentials_released',
                title: 'Room details are live!',
                body: "Room ID and password for {$tournament->title} are now available in the app.",
                data: ['tournament_id' => (string) $tournament->id, 'deep_link' => "tournament/{$tournament->id}"],
                idempotencyKey: "tournament.credentials_released:{$tournament->id}",
            );
        }

        return $credential;
    }
}
