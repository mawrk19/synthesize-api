<?php

namespace App\Modules\Orchestration\Http\Resources;

use App\Modules\Orchestration\Models\PipelineRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PipelineRun */
class PipelineRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'srs_document_id' => $this->srs_document_id,
            'status' => $this->status?->value,
            'current_phase' => $this->current_phase?->value,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by_user_id' => $this->approved_by_user_id,
            'error_message' => $this->error_message,
            'tasks_count' => $this->when(isset($this->tasks_count), $this->tasks_count),
            'tasks' => PipelineTaskResource::collection($this->whenLoaded('tasks')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
