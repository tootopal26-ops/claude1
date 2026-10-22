<?php

namespace Tests\Feature\Legal;

use App\Domain\Legal\PublishLegalDocumentAction;
use App\Domain\Settings\PlatformSettingsService;
use App\Models\LegalAcceptance;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationLegalAndJurisdictionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'eligible_player',
            'mobile' => '9123456700',
            'email' => 'eligible@example.com',
            'date_of_birth' => '1995-05-01',
            'state' => 'Maharashtra',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ], $overrides);
    }

    public function test_registration_without_accepting_terms_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['accept_terms' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('accept_terms');
    }

    public function test_registration_without_a_state_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['state' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('state');
    }

    public function test_registration_from_a_blocked_state_is_rejected(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        app(PlatformSettingsService::class)->set('blocked_states', ['Telangana'], $staff);

        $this->postJson('/api/v1/auth/register', $this->payload(['state' => 'Telangana']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('state');

        $this->postJson('/api/v1/auth/register', $this->payload(['state' => ' telangana ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('state');
    }

    public function test_registration_from_an_unblocked_state_succeeds(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        app(PlatformSettingsService::class)->set('blocked_states', ['Telangana'], $staff);

        $this->postJson('/api/v1/auth/register', $this->payload(['state' => 'Maharashtra']))
            ->assertCreated();
    }

    public function test_a_successful_registration_records_acceptance_of_every_current_document(): void
    {
        $staff = StaffUser::factory()->create();
        app(PublishLegalDocumentAction::class)->execute('terms_and_conditions', 'Terms', 'Body', $staff);
        app(PublishLegalDocumentAction::class)->execute('privacy_policy', 'Privacy', 'Body', $staff);

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $user = User::where('username', 'eligible_player')->firstOrFail();
        $this->assertSame(2, LegalAcceptance::where('user_id', $user->id)->count());
    }

    public function test_registration_succeeds_even_when_no_legal_documents_have_been_published_yet(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $user = User::where('username', 'eligible_player')->firstOrFail();
        $this->assertSame(0, LegalAcceptance::where('user_id', $user->id)->count());
    }
}
