<?php

namespace Tests\Feature\Legal;

use App\Domain\Settings\PlatformSettingsService;
use App\Domain\Wallet\WalletService;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlatformSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_the_env_backed_default_when_no_row_exists(): void
    {
        $this->assertSame((int) config('minimax.tickets_max_per_tournament'), app(PlatformSettingsService::class)->get('tickets_max_per_tournament'));
    }

    public function test_set_creates_a_row_updates_config_immediately_and_audit_logs(): void
    {
        $staff = StaffUser::factory()->create();

        app(PlatformSettingsService::class)->set('tickets_max_per_tournament', 7, $staff);

        $this->assertSame(7, config('minimax.tickets_max_per_tournament'));
        $this->assertDatabaseHas('platform_settings', ['key' => 'tickets_max_per_tournament', 'updated_by_staff_id' => $staff->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_settings.updated', 'actor_id' => $staff->id]);
    }

    public function test_set_on_an_unknown_key_is_rejected(): void
    {
        $staff = StaffUser::factory()->create();

        $this->expectException(ValidationException::class);
        app(PlatformSettingsService::class)->set('not_a_real_setting', 'x', $staff);
    }

    public function test_get_on_an_unknown_key_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(PlatformSettingsService::class)->get('not_a_real_setting');
    }

    public function test_all_returns_every_catalog_key(): void
    {
        $all = app(PlatformSettingsService::class)->all();

        $this->assertSame(array_keys(PlatformSettingsService::CATALOG), array_keys($all));
    }

    public function test_a_changed_wallet_risk_alert_threshold_is_respected_by_its_real_consumer(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        app(PlatformSettingsService::class)->set('wallet_risk_alert_threshold', '50.00', $staff);

        $user = User::factory()->create();
        app(WalletService::class)->creditWinnings($user, '100.00', 'seed:'.$user->id);
        app(WalletService::class)->manualAdjust($user, '75.00', 'winnings', 'test bump', $staff, 'test-adjust:'.$user->id);

        $this->assertDatabaseHas('risk_alerts', ['type' => 'manual_wallet_adjustment_over_threshold', 'target_user_id' => $user->id]);
    }
}
