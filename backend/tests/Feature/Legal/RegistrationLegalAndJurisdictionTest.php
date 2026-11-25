<?php

namespace Tests\Feature\Legal;

use App\Domain\Legal\PublishLegalDocumentAction;
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
