<?php

namespace App\Jobs;

use App\Models\DevicePushToken;
use App\Models\Notification as NotificationModel;
use App\Models\NotificationBatch;
use App\Models\PushDeliveryLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;

class SendPushNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $notificationId,
        public int $devicePushTokenId,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(Messaging $messaging): void
    {
        $notification = NotificationModel::find($this->notificationId);
        $deviceToken = DevicePushToken::find($this->devicePushTokenId);

        if ($notification === null || $deviceToken === null) {
            return;
        }

        // Idempotency: if a previous attempt already logged a successful
        // send for this exact (notification, device) pair, a retry of this
        // job (e.g. after a queue worker restart mid-batch) must not push
        // the same alert to the same device twice.
        $alreadySent = PushDeliveryLog::where('notification_id', $notification->id)
            ->where('device_push_token_id', $deviceToken->id)
            ->where('status', 'sent')
            ->exists();

        if ($alreadySent) {
            return;
        }

        try {
            $message = CloudMessage::new()
                ->withToken($deviceToken->token)
                ->withNotification(FirebaseNotification::create($notification->title, $notification->body))
                ->withData(array_map('strval', $notification->data ?? []));

            $messaging->send($message);

            PushDeliveryLog::create([
                'notification_id' => $notification->id,
                'device_push_token_id' => $deviceToken->id,
                'status' => 'sent',
                'attempted_at' => now(),
            ]);

            NotificationBatch::where('id', $notification->notification_batch_id)->increment('push_sent_count');
        } catch (NotFound $exception) {
            // The token is no longer registered with FCM (app uninstalled,
            // token rotated, etc.) — clean it up so future batches don't
            // keep retrying a dead device.
            PushDeliveryLog::create([
                'notification_id' => $notification->id,
                'device_push_token_id' => $deviceToken->id,
                'status' => 'invalid_token',
                'error_message' => $exception->getMessage(),
                'attempted_at' => now(),
            ]);

            NotificationBatch::where('id', $notification->notification_batch_id)->increment('push_failed_count');

            $deviceToken->delete();
        } catch (MessagingException $exception) {
            // Logged on every attempt for audit history, but push_failed_count
            // only increments once all retries are exhausted (see failed()
            // below) — otherwise a message that succeeds on its 2nd try would
            // be double-counted as both a failure and a success.
            PushDeliveryLog::create([
                'notification_id' => $notification->id,
                'device_push_token_id' => $deviceToken->id,
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'attempted_at' => now(),
            ]);

            Log::warning('Push notification delivery attempt failed', [
                'notification_id' => $notification->id,
                'device_push_token_id' => $deviceToken->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $notification = NotificationModel::find($this->notificationId);

        if ($notification !== null) {
            NotificationBatch::where('id', $notification->notification_batch_id)->increment('push_failed_count');
        }
    }
}
