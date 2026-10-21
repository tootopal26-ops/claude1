<?php

namespace App\Domain\Notifications;

use App\Jobs\SendPushNotificationJob;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for creating a notification: it always writes the
 * durable in-app record first (the source of truth per the project's
 * notification policy — push delivery is best-effort on top of it), then
 * queues one push job per active device token. Every later phase that
 * needs to notify users on a real event (results published, a withdrawal
 * paid, ...) should call this rather than touching the tables directly.
 */
class NotificationService
{
    /**
     * @param  Collection<int, User>|array<int, User>  $recipients
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $audienceMeta
     */
    public function notify(
        iterable $recipients,
        string $type,
        string $title,
        string $body,
        array $data = [],
        string $audienceType = 'all',
        array $audienceMeta = [],
        ?StaffUser $createdByStaff = null,
        ?string $idempotencyKey = null,
    ): NotificationBatch {
        if ($idempotencyKey !== null) {
            $existing = NotificationBatch::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $sanitizedData = SanitizesNotificationData::sanitize($data);
        $recipients = collect($recipients)->unique('id')->values();

        return DB::transaction(function () use (
            $recipients, $type, $title, $body, $sanitizedData,
            $audienceType, $audienceMeta, $createdByStaff, $idempotencyKey,
        ) {
            $batch = NotificationBatch::create([
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $sanitizedData,
                'audience_type' => $audienceType,
                'audience_meta' => $audienceMeta,
                'created_by_staff_id' => $createdByStaff?->id,
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
            ]);

            $batch->recipient_count = $recipients->count();
            $batch->save();

            foreach ($recipients as $user) {
                $notification = Notification::create([
                    'notification_batch_id' => $batch->id,
                    'user_id' => $user->id,
                    'title' => $title,
                    'body' => $body,
                    'data' => $sanitizedData,
                ]);

                foreach ($user->devicePushTokens()->get() as $deviceToken) {
                    SendPushNotificationJob::dispatch($notification->id, $deviceToken->id)
                        ->afterCommit();
                }
            }

            $batch->status = 'sent';
            $batch->sent_at = now();
            $batch->save();

            return $batch;
        });
    }
}
