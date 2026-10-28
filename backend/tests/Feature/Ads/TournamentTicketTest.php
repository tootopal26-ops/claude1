<?php

namespace Tests\Feature\Ads;

use App\Models\AdCallbackLog;
use App\Models\Tournament;
use App\Models\TournamentAdTicket;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TournamentTicketTest extends TestCase
{
    use ConfiguresAds;
    use RefreshDatabase;

    private function openTicketClaim(Tournament $tournament): string
    {
        return $this->postJson('/api/v1/ads/claims', ['purpose' => 'tournament_ticket', 'tournament_id' => $tournament->id])
            ->assertCreated()
            ->json('data.token');
    }

    private function join(Tournament $tournament)
    {
        return $this->postJson("/api/v1/tournaments/{$tournament->id}/join", ['players' => [['in_game_name' => 'Ace', 'uid' => null]]]);
    }

    public function test_a_three_ticket_tournament_unlocks_only_after_three_verified_ads(): void
    {
        $this->enableAds();
        $player = User::factory()->create();
        $tournament = Tournament::factory()->create(['required_ad_tickets' => 3]);
        Sanctum::actingAs($player);

        $this->getJson("/api/v1/tournaments/{$tournament->id}")->assertJsonPath('data.ad_tickets_required', 3)->assertJsonPath('data.ad_tickets_earned', 0);
        $this->join($tournament)->assertUnprocessable()->assertJsonPath('errors.tournament.0', 'Watch 3 more ads to unlock this tournament.');

        foreach ([1, 2, 3] as $n) {
            $token = $this->openTicketClaim($tournament);
            $this->sendCallback($this->signedCallback($token, "evt-{$n}"))->assertOk()->assertSeeText("evt-{$n}:OK");
            $this->getJson("/api/v1/tournaments/{$tournament->id}")->assertJsonPath('data.ad_tickets_earned', $n);
            if ($n < 3) {
                $this->join($tournament)->assertUnprocessable();
            }
        }

        // 3/3 does not register the player by itself — the normal join still has to succeed.
        $this->assertDatabaseMissing('tournament_entries', ['tournament_id' => $tournament->id, 'user_id' => $player->id]);
        $this->join($tournament)->assertCreated();
    }

    public function test_the_default_free_tournament_needs_no_tickets_and_paid_tournaments_never_do(): void
    {
        $this->enableAds();
        Sanctum::actingAs(User::factory()->create());

        $free = Tournament::factory()->create(['required_ad_tickets' => 0]);
        $this->join($free)->assertCreated();

        $paid = Tournament::factory()->paid(10)->create(['required_ad_tickets' => 3]);
        $this->getJson("/api/v1/tournaments/{$paid->id}")->assertJsonPath('data.ad_tickets_required', 0);
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'tournament_ticket', 'tournament_id' => $paid->id])->assertUnprocessable();
    }

    public function test_a_duplicate_callback_for_the_same_event_counts_once(): void
    {
        $this->enableAds();
        $player = User::factory()->create();
        $tournament = Tournament::factory()->create(['required_ad_tickets' => 3]);
        Sanctum::actingAs($player);

        $token = $this->openTicketClaim($tournament);
        $params = $this->signedCallback($token, 'evt-dup');
        $this->sendCallback($params)->assertOk();
        $this->sendCallback($params)->assertOk()->assertSeeText('evt-dup:OK');

        $this->assertSame(1, TournamentAdTicket::where('user_id', $player->id)->count());
        $this->assertDatabaseHas('ad_callback_logs', ['event_id' => 'evt-dup', 'outcome' => 'duplicate']);
    }

    public function test_one_provider_event_cannot_complete_two_claims(): void
    {
        $this->enableAds();
        $player = User::factory()->create();
        $first = Tournament::factory()->create(['required_ad_tickets' => 2]);
        $second = Tournament::factory()->create(['required_ad_tickets' => 2]);
        Sanctum::actingAs($player);

        $this->sendCallback($this->signedCallback($this->openTicketClaim($first), 'evt-shared'))->assertOk();
        $this->sendCallback($this->signedCallback($this->openTicketClaim($second), 'evt-shared'))->assertOk();

        $this->assertSame(1, TournamentAdTicket::count());
        $this->assertSame(0, TournamentAdTicket::where('tournament_id', $second->id)->count());
        $this->assertDatabaseHas('ad_callback_logs', ['event_id' => 'evt-shared', 'outcome' => 'event_reused']);
    }

    public function test_forged_unsigned_or_unconfigured_callbacks_never_grant_a_ticket(): void
    {
        $this->enableAds();
        $tournament = Tournament::factory()->create(['required_ad_tickets' => 1]);
        Sanctum::actingAs(User::factory()->create());
        $token = $this->openTicketClaim($tournament);

        $this->sendCallback($this->signedCallback($token, 'evt-forged', key: 'wrong-key'))->assertForbidden();

        $unsigned = $this->signedCallback($token, 'evt-unsigned');
        unset($unsigned['signature']);
        $this->sendCallback($unsigned)->assertForbidden();

        $stale = $this->signedCallback($token, 'evt-stale', timestamp: now()->utc()->subDays(5)->format('YmdHi'));
        $this->sendCallback($stale)->assertForbidden();

        config(['minimax.levelplay_s2s_private_key' => null]);
        $this->sendCallback($this->signedCallback($token, 'evt-nokey'))->assertForbidden();

        $this->assertSame(0, TournamentAdTicket::count());
        $this->assertEqualsCanonicalizing(
            ['bad_signature', 'missing_parameters', 'stale_timestamp', 'not_configured'],
            AdCallbackLog::pluck('outcome')->all(),
        );
    }

    public function test_a_closed_ad_or_an_unanswered_claim_grants_nothing(): void
    {
        $this->enableAds();
        $player = User::factory()->create();
        $tournament = Tournament::factory()->create(['required_ad_tickets' => 2]);
        Sanctum::actingAs($player);

        $token = $this->openTicketClaim($tournament);
        $this->postJson("/api/v1/ads/claims/{$token}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->sendCallback($this->signedCallback($token, 'evt-late'))->assertOk();
        $this->assertSame(0, TournamentAdTicket::count());

        $second = $this->openTicketClaim($tournament);
        $this->travel(2)->hours();
        $this->artisan('ads:maintain')->assertSuccessful();
        $this->assertDatabaseHas('rewarded_claims', ['token' => $second, 'status' => 'expired']);
        $this->sendCallback($this->signedCallback($second, 'evt-too-late'))->assertOk();
        $this->assertSame(0, TournamentAdTicket::count());
    }

    public function test_tickets_never_exceed_the_requirement_and_reopening_reuses_the_pending_claim(): void
    {
        $this->enableAds();
        $tournament = Tournament::factory()->create(['required_ad_tickets' => 1]);
        Sanctum::actingAs(User::factory()->create());

        $token = $this->openTicketClaim($tournament);
        $this->assertSame($token, $this->openTicketClaim($tournament));

        $this->sendCallback($this->signedCallback($token, 'evt-a'))->assertOk();
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'tournament_ticket', 'tournament_id' => $tournament->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.tournament.0', 'You already have every ticket this tournament needs.');
        $this->assertSame(1, TournamentAdTicket::count());
    }

    public function test_a_player_cannot_see_or_cancel_someone_elses_claim(): void
    {
        $this->enableAds();
        $tournament = Tournament::factory()->create(['required_ad_tickets' => 1]);
        Sanctum::actingAs(User::factory()->create());
        $token = $this->openTicketClaim($tournament);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/ads/claims/{$token}")->assertNotFound();
        $this->postJson("/api/v1/ads/claims/{$token}/cancel")->assertNotFound();
    }

    public function test_full_or_closed_tournaments_refuse_ticket_ads(): void
    {
        $this->enableAds();
        Sanctum::actingAs(User::factory()->create());

        $full = Tournament::factory()->create(['required_ad_tickets' => 1, 'total_slots' => 1]);
        TournamentEntry::factory()->create(['tournament_id' => $full->id]);
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'tournament_ticket', 'tournament_id' => $full->id])->assertUnprocessable();

        $closed = Tournament::factory()->create(['required_ad_tickets' => 1, 'status' => 'ongoing']);
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'tournament_ticket', 'tournament_id' => $closed->id])->assertUnprocessable();
    }

    public function test_no_claim_opens_while_ads_are_switched_off(): void
    {
        $this->enableAds();
        $tournament = Tournament::factory()->create(['required_ad_tickets' => 1]);
        Sanctum::actingAs(User::factory()->create());

        config(['minimax.ads_master_enabled' => false]);
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'tournament_ticket', 'tournament_id' => $tournament->id])->assertUnprocessable();
        $this->getJson('/api/v1/ads/config')->assertJsonPath('data.enabled', false)->assertJsonPath('data.placements', []);

        config(['minimax.ads_master_enabled' => true, 'minimax.ads_rewarded_enabled' => false]);
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'tournament_ticket', 'tournament_id' => $tournament->id])->assertUnprocessable();
    }

    public function test_the_simulation_endpoint_is_unavailable_unless_explicitly_allowed_outside_production(): void
    {
        $this->enableAds();
        $tournament = Tournament::factory()->create(['required_ad_tickets' => 1]);
        Sanctum::actingAs(User::factory()->create());
        $token = $this->openTicketClaim($tournament);

        $this->postJson("/api/v1/ads/claims/{$token}/simulate")->assertNotFound();

        config(['minimax.ads_allow_simulated_rewards' => true]);
        $this->app['env'] = 'production';
        $this->postJson("/api/v1/ads/claims/{$token}/simulate")->assertNotFound();
        $this->assertSame(0, TournamentAdTicket::count());
    }
}
