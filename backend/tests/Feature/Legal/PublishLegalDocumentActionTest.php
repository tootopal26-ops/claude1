<?php

namespace Tests\Feature\Legal;

use App\Domain\Legal\PublishLegalDocumentAction;
use App\Models\LegalDocument;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PublishLegalDocumentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_publish_of_a_type_is_version_1_and_current(): void
    {
        $staff = StaffUser::factory()->create();

        $document = app(PublishLegalDocumentAction::class)->execute('terms_and_conditions', 'Terms', 'Body text', $staff);

        $this->assertSame(1, $document->version);
        $this->assertTrue($document->is_current);
        $this->assertDatabaseHas('audit_logs', ['action' => 'legal_document.published', 'actor_id' => $staff->id]);
    }

    public function test_publishing_a_revision_increments_the_version_and_supersedes_the_old_one(): void
    {
        $staff = StaffUser::factory()->create();
        $action = app(PublishLegalDocumentAction::class);

        $v1 = $action->execute('terms_and_conditions', 'Terms v1', 'Body v1', $staff);
        $v2 = $action->execute('terms_and_conditions', 'Terms v2', 'Body v2', $staff);

        $this->assertSame(2, $v2->version);
        $this->assertTrue($v2->is_current);
        $this->assertFalse($v1->fresh()->is_current);
    }

    public function test_the_two_document_types_version_independently(): void
    {
        $staff = StaffUser::factory()->create();
        $action = app(PublishLegalDocumentAction::class);

        $terms = $action->execute('terms_and_conditions', 'Terms', 'Body', $staff);
        $privacy = $action->execute('privacy_policy', 'Privacy', 'Body', $staff);

        $this->assertSame(1, $terms->version);
        $this->assertSame(1, $privacy->version);
        $this->assertSame(2, LegalDocument::where('is_current', true)->count());
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        $staff = StaffUser::factory()->create();

        $this->expectException(ValidationException::class);
        app(PublishLegalDocumentAction::class)->execute('eula', 'Title', 'Body', $staff);
    }

    public function test_empty_title_or_content_is_rejected(): void
    {
        $staff = StaffUser::factory()->create();
        $action = app(PublishLegalDocumentAction::class);

        $this->expectException(ValidationException::class);
        $action->execute('terms_and_conditions', '  ', 'Body', $staff);
    }
}
