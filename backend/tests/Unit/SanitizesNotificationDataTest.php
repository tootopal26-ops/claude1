<?php

namespace Tests\Unit;

use App\Domain\Notifications\SanitizesNotificationData;
use Tests\TestCase;

class SanitizesNotificationDataTest extends TestCase
{
    public function test_it_strips_keys_that_look_like_secrets(): void
    {
        $sanitized = SanitizesNotificationData::sanitize([
            'tournament_id' => '5',
            'room_password' => 'super-secret',
            'api_key' => 'abc123',
            'upi_id' => 'player@bank',
            'auth_token' => 'xyz',
            'otp_code' => '123456',
        ]);

        $this->assertSame(['tournament_id' => '5'], $sanitized);
    }

    public function test_it_leaves_ordinary_data_untouched(): void
    {
        $sanitized = SanitizesNotificationData::sanitize([
            'tournament_id' => '5',
            'deep_link' => 'tournament/5',
        ]);

        $this->assertSame(['tournament_id' => '5', 'deep_link' => 'tournament/5'], $sanitized);
    }
}
