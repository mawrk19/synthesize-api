<?php

namespace App\Modules\Orchestration\Http\Resources;

use App\Modules\Orchestration\Models\PipelineTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PipelineTask */
class PipelineTaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pipeline_run_id' => $this->pipeline_run_id,
            'project_id' => $this->project_id,
            'requirement_id' => $this->requirement_id,
            'parent_task_id' => $this->parent_task_id,
            'depends_on_task_id' => $this->depends_on_task_id,
            'sort_order' => $this->sort_order,
            'title' => $this->title,
            'description' => $this->description,
            'agent_role' => $this->agent_role?->value,
            'status' => $this->status?->value,
            'included_in_plan' => (bool) ($this->included_in_plan ?? true),
            'prompt_template' => $this->prompt_template,
            'files_hint' => $this->files_hint,
            'attempts' => $this->attempts,
            'error_message' => $this->error_message,
            'audit_report' => $this->audit_report,
            'requirement' => $this->whenLoaded('requirement', fn () => $this->requirement ? [
                'id' => $this->requirement->id,
                'code' => $this->requirement->code,
                'title' => $this->requirement->title,
            ] : null),
            'code_change' => $this->whenLoaded('codeChange', fn () => $this->codeChange
                ? new TaskCodeChangeResource($this->codeChange)
                : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
