<?php

namespace App\Modules\Projects\Http\Resources;

use App\Modules\Projects\Models\Requirement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Requirement */
class RequirementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'srs_document_id' => $this->srs_document_id,
            'type' => $this->type,
            'code' => $this->code,
            'title' => $this->title,
            'body' => $this->body,
            'priority' => $this->priority,
            'gherkin' => $this->gherkin,
            'validation_flags' => $this->validation_flags,
            'comments_count' => $this->whenCounted('comments'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
