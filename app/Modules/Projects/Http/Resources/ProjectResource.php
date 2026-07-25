<?php

namespace App\Modules\Projects\Http\Resources;

use App\Modules\Projects\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'documents_count' => $this->whenCounted('documents'),
            'requirements_count' => $this->whenCounted('requirements'),
            'context_files_count' => $this->whenCounted('contextFiles'),
            'intake_sessions_count' => $this->whenCounted('intakeSessions'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
