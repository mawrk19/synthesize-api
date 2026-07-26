<?php

namespace App\Modules\Orchestration\Http\Resources;

use App\Modules\Orchestration\Enums\AgentRole;
use App\Modules\Orchestration\Models\PipelineRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PipelineRun */
class PipelineRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $approvedByName = null;
        if ($this->relationLoaded('approvedBy') && $this->approvedBy) {
            $approvedByName = trim(
                ((string) $this->approvedBy->first_name).' '.((string) $this->approvedBy->last_name)
            ) ?: null;
        }

        $approvedTaskCount = null;
        $skippedTaskCount = null;
        if ($this->relationLoaded('tasks')) {
            $devTasks = $this->tasks->where('agent_role', AgentRole::Developer);
            $approvedTaskCount = $devTasks->where('included_in_plan', true)->count();
            $skippedTaskCount = $devTasks->where('included_in_plan', false)->count();
        }

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'srs_document_id' => $this->srs_document_id,
            'status' => $this->status?->value,
            'current_phase' => $this->current_phase?->value,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by_user_id' => $this->approved_by_user_id,
            'approved_by_name' => $approvedByName,
            'approved_task_count' => $this->when($approvedTaskCount !== null, $approvedTaskCount),
            'skipped_task_count' => $this->when($skippedTaskCount !== null, $skippedTaskCount),
            'error_message' => $this->error_message,
            'tasks_count' => $this->when(isset($this->tasks_count), $this->tasks_count),
            'tasks' => PipelineTaskResource::collection($this->whenLoaded('tasks')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
