<?php

namespace App\Modules\Projects\Http\Resources;

use App\Modules\Projects\Models\IntakeSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IntakeSession */
class IntakeSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'source_type' => $this->source_type->value,
            'raw_content' => $this->raw_content,
            'structured_draft' => $this->structured_draft,
            'status' => $this->status->value,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
