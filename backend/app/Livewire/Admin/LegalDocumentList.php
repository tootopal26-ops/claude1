<?php

namespace App\Livewire\Admin;

use App\Models\LegalDocument;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class LegalDocumentList extends Component
{
    public function render()
    {
        return view('livewire.admin.legal-document-list', [
            'documentsByType' => LegalDocument::with('publishedByStaff')
                ->orderByDesc('version')
                ->get()
                ->groupBy('type'),
        ]);
    }
}
