<?php

namespace Tests\Feature\Legal;

use App\Domain\Legal\AcceptCurrentLegalDocumentsAction;
use App\Domain\Legal\PublishLegalDocumentAction;
use App\Models\AuditLog;
use App\Models\LegalAcceptance;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcceptCurrentLegalDocumentsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepting_with_no_current_documents_does_nothing(): void
    {
        $user = User::factory()->create();

        app(AcceptCurrentLegalDocumentsAction::class)->execute($user, '127.0.0.1');

        $this->assertSame(0, LegalAcceptance::count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'legal.accepted']);
    }

    public function test_accepting_creates_one_row_per_current_document(): void
    {
        $staff = StaffUser::factory()->create();
        app(PublishLegalDocumentAction::class)->execute('terms_and_conditions', 'Terms', 'Body', $staff);
        app(PublishLegalDocumentAction::class)->execute('privacy_policy', 'Privacy', 'Body', $staff);

        $user = User::factory()->create();
        app(AcceptCurrentLegalDocumentsAction::class)->execute($user, '127.0.0.1');

        $this->assertSame(2, LegalAcceptance::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'legal.accepted', 'actor_id' => $user->id]);
    }

    public function test_reaccepting_is_idempotent_and_does_not_duplicate_the_audit_log(): void
    {
        $staff = StaffUser::factory()->create();
        app(PublishLegalDocumentAction::class)->execute('terms_and_conditions', 'Terms', 'Body', $staff);

        $user = User::factory()->create();
        $action = app(AcceptCurrentLegalDocumentsAction::class);
        $action->execute($user, '127.0.0.1');
        $action->execute($user, '127.0.0.1');

        $this->assertSame(1, LegalAcceptance::where('user_id', $user->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'legal.accepted')->count());
    }

    public function test_only_newly_accepted_documents_are_audit_logged(): void
    {
        $staff = StaffUser::factory()->create();
        $action = app(PublishLegalDocumentAction::class);
        $terms = $action->execute('terms_and_conditions', 'Terms', 'Body', $staff);

        $user = User::factory()->create();
        app(AcceptCurrentLegalDocumentsAction::class)->execute($user, '127.0.0.1');

        $privacy = $action->execute('privacy_policy', 'Privacy', 'Body', $staff);
        app(AcceptCurrentLegalDocumentsAction::class)->execute($user, '127.0.0.1');

        $this->assertSame(2, LegalAcceptance::where('user_id', $user->id)->count());
        $this->assertSame(2, AuditLog::where('action', 'legal.accepted')->count());
    }
}
