<?php

namespace Tests\Feature\Legal;

use App\Domain\Legal\AcceptCurrentLegalDocumentsAction;
use App\Domain\Legal\PublishLegalDocumentAction;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_documents_are_public_and_exclude_superseded_versions(): void
    {
        $staff = StaffUser::factory()->create();
        $action = app(PublishLegalDocumentAction::class);
        $action->execute('terms_and_conditions', 'Terms v1', 'Body v1', $staff);
        $action->execute('terms_and_conditions', 'Terms v2', 'Body v2', $staff);

        $response = $this->getJson('/api/v1/legal/current');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Terms v2', $response->json('data.0.title'));
    }

    public function test_acceptance_status_requires_authentication(): void
    {
        $this->getJson('/api/v1/legal/acceptance-status')->assertUnauthorized();
    }

    public function test_accept_requires_authentication(): void
    {
        $this->postJson('/api/v1/legal/accept')->assertUnauthorized();
    }

    public function test_acceptance_status_is_clear_immediately_after_registration(): void
    {
        $staff = StaffUser::factory()->create();
        app(PublishLegalDocumentAction::class)->execute('terms_and_conditions', 'Terms', 'Body', $staff);

        $user = User::factory()->create();
        app(AcceptCurrentLegalDocumentsAction::class)->execute($user);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/legal/acceptance-status');

        $response->assertOk()->assertJsonPath('data.needs_acceptance', false);
        $this->assertSame([], $response->json('data.outstanding'));
    }

    public function test_a_revised_document_reopens_acceptance_for_a_user_who_already_accepted_it(): void
    {
        $staff = StaffUser::factory()->create();
        $action = app(PublishLegalDocumentAction::class);
        $action->execute('terms_and_conditions', 'Terms v1', 'Body v1', $staff);

        $user = User::factory()->create();
        app(AcceptCurrentLegalDocumentsAction::class)->execute($user);

        $action->execute('terms_and_conditions', 'Terms v2', 'Body v2', $staff);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/legal/acceptance-status');

        $response->assertOk()->assertJsonPath('data.needs_acceptance', true);
        $this->assertCount(1, $response->json('data.outstanding'));
        $this->assertSame('Terms v2', $response->json('data.outstanding.0.title'));
    }

    public function test_accepting_clears_the_outstanding_status(): void
    {
        $staff = StaffUser::factory()->create();
        app(PublishLegalDocumentAction::class)->execute('terms_and_conditions', 'Terms', 'Body', $staff);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/legal/accept')
            ->assertOk()
            ->assertJsonPath('data.accepted', true);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/legal/acceptance-status')
            ->assertJsonPath('data.needs_acceptance', false);
    }
}
