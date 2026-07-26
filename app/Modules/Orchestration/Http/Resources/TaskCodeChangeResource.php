<?php

namespace App\Modules\Orchestration\Http\Resources;

use App\Modules\Orchestration\Models\TaskCodeChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaskCodeChange */
class TaskCodeChangeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pipeline_task_id' => $this->pipeline_task_id,
            'branch_name' => $this->branch_name,
            'commit_sha' => $this->commit_sha,
            'pr_number' => $this->pr_number,
            'pr_url' => $this->pr_url,
            'pr_status' => $this->pr_status?->value,
            'unified_diff' => $this->unified_diff,
            'files_changed' => $this->files_changed,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
