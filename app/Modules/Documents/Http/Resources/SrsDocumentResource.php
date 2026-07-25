<?php

namespace App\Modules\Documents\Http\Resources;

use App\Modules\Documents\Models\SrsDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SrsDocument */
class SrsDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'source_notes' => $this->source_notes,
            'source_filename' => $this->source_filename,
            'status' => $this->status->value,
            'generated_srs' => $this->generated_srs,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
