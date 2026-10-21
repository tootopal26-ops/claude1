<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\NotificationService;
use App\Jobs\SendPushNotificationJob;
use App\Models\DevicePushToken;
use App\Models\Notification;
use App\Models\PushDeliveryLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Tests\TestCase;

class SendPushNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_joining_a_tournament_dispatches_a_push_job_per_active_device_token(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        DevicePushToken::factory()->for($user)->create();
        DevicePushToken::factory()->for($user)->create();

        app(NotificationService::class)->notify([$user], 'test.event', 'Hello', 'Body', idempotencyKey: 'batch-1');

        Bus::assertDispatchedTimes(SendPushNotificationJob::class, 2);
    }

    public function test_a_successful_send_is_logged_and_increments_the_batch_sent_count(): void
    {
        // Faked so notify()'s own automatic dispatch (QUEUE_CONNECTION=sync in
        // testing runs jobs inline) doesn't consume the mock itself — this
        // test drives handle() manually and needs the one `once()` call below.
        Bus::fake();
        $this->mock(Messaging::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andReturn([]);
        });

        $user = User::factory()->create();
        $deviceToken = DevicePushToken::factory()->for($user)->create();

        $batch = app(NotificationService::class)->notify([$user], 'test.event', 'Hello', 'Body', idempotencyKey: 'batch-1');
        $notification = Notification::where('notification_batch_id', $batch->id)->firstOrFail();

        (new SendPushNotificationJob($notification->id, $deviceToken->id))->handle(app(Messaging::class));

        $this->assertDatabaseHas('push_delivery_logs', [
            'notification_id' => $notification->id,
            'device_push_token_id' => $deviceToken->id,
            'status' => 'sent',
        ]);
        $this->assertSame(1, $batch->fresh()->push_sent_count);
    }

    public function test_sending_twice_to_an_already_delivered_device_is_a_no_op(): void
    {
        Bus::fake();
        $this->mock(Messaging::class, function ($mock) {
            // Exactly once: the second handle() call must short-circuit on the
            // existing 'sent' PushDeliveryLog rather than calling send() again.
            $mock->shouldReceive('send')->once()->andReturn([]);
        });

        $user = User::factory()->create();
        $deviceToken = DevicePushToken::factory()->for($user)->create();
        $batch = app(NotificationService::class)->notify([$user], 'test.event', 'Hello', 'Body', idempotencyKey: 'batch-1');
        $notification = Notification::where('notification_batch_id', $batch->id)->firstOrFail();

        $job = new SendPushNotificationJob($notification->id, $deviceToken->id);
        $job->handle(app(Messaging::class));
        $job->handle(app(Messaging::class));

        $this->assertSame(1, PushDeliveryLog::where('notification_id', $notification->id)->count());
    }

    public function test_an_unregistered_token_is_deleted_and_logged_as_invalid(): void
    {
        Bus::fake();
        $this->mock(Messaging::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andThrow(NotFound::becauseTokenNotFound('dead-token'));
        });

        $user = User::factory()->create();
        $deviceToken = DevicePushToken::factory()->for($user)->create(['token' => 'dead-token']);
        $batch = app(NotificationService::class)->notify([$user], 'test.event', 'Hello', 'Body', idempotencyKey: 'batch-1');
        $notification = Notification::where('notification_batch_id', $batch->id)->firstOrFail();

        (new SendPushNotificationJob($notification->id, $deviceToken->id))->handle(app(Messaging::class));

        $this->assertDatabaseMissing('device_push_tokens', ['id' => $deviceToken->id]);
        $this->assertDatabaseHas('push_delivery_logs', [
            'notification_id' => $notification->id,
            'status' => 'invalid_token',
        ]);
        $this->assertSame(1, $batch->fresh()->push_failed_count);
    }

    public function test_a_transient_failure_is_logged_per_attempt_and_rethrown_for_the_queue_to_retry(): void
    {
        Bus::fake();
        $this->mock(Messaging::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andThrow(new ServerUnavailable('temporary outage'));
        });

        $user = User::factory()->create();
        $deviceToken = DevicePushToken::factory()->for($user)->create();
        $batch = app(NotificationService::class)->notify([$user], 'test.event', 'Hello', 'Body', idempotencyKey: 'batch-1');
        $notification = Notification::where('notification_batch_id', $batch->id)->firstOrFail();

        $job = new SendPushNotificationJob($notification->id, $deviceToken->id);

        try {
            $job->handle(app(Messaging::class));
            $this->fail('Expected the messaging exception to propagate so the queue retries the job.');
        } catch (ServerUnavailable) {
            // expected
        }

        $this->assertDatabaseHas('push_delivery_logs', [
            'notification_id' => $notification->id,
            'status' => 'failed',
        ]);
        // Not yet incremented — only failed() (called once retries are exhausted) increments this.
        $this->assertSame(0, $batch->fresh()->push_failed_count);

        $job->failed(new ServerUnavailable('temporary outage'));
        $this->assertSame(1, $batch->fresh()->push_failed_count);
    }
}
