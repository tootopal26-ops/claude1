<?php

namespace App\Http\Resources;

use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LegalDocument */
class LegalDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'version' => $this->version,
            'title' => $this->title,
            'content' => $this->content,
            'published_at' => $this->published_at->toIso8601String(),
        ];
    }
}
