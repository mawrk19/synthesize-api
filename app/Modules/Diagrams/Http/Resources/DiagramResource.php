<?php

namespace App\Modules\Diagrams\Http\Resources;

use App\Modules\Diagrams\Models\Diagram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Diagram */
class DiagramResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'srs_document_id' => $this->srs_document_id,
            'type' => $this->type->value,
            'title' => $this->title,
            'mermaid_source' => $this->mermaid_source,
            'status' => $this->status->value,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
