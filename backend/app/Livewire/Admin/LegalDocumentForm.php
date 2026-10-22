<?php

namespace App\Livewire\Admin;

use App\Domain\Legal\PublishLegalDocumentAction;
use App\Models\LegalDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class LegalDocumentForm extends Component
{
    public string $type = 'terms_and_conditions';

    public string $title = 'Terms & Conditions';

    public string $content = '';

    public ?string $formError = null;

    public function updatedType(): void
    {
        $current = LegalDocument::where('type', $this->type)->where('is_current', true)->first();

        $this->title = $current->title ?? ($this->type === 'privacy_policy' ? 'Privacy Policy' : 'Terms & Conditions');
        $this->content = $current->content ?? '';
    }

    public function publish(PublishLegalDocumentAction $action): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('legal.manage'), 403);

        $this->formError = null;

        $this->validate([
            'type' => ['required', 'in:terms_and_conditions,privacy_policy'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        try {
            $action->execute($this->type, $this->title, $this->content, Auth::guard('staff')->user());
        } catch (ValidationException $exception) {
            $this->formError = collect($exception->errors())->flatten()->first();

            return;
        }

        session()->flash('status', 'New version published. Every player will be asked to re-accept it.');

        $this->redirectRoute('admin.legal.index', navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.legal-document-form');
    }
}
