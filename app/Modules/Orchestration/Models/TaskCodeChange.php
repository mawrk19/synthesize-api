<?php

namespace App\Modules\Orchestration\Models;

use App\Modules\Orchestration\Enums\PrStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCodeChange extends Model
{
    use HasUuids;

    protected $table = 'task_code_changes';

    protected $fillable = [
        'pipeline_task_id',
        'branch_name',
        'commit_sha',
        'pr_number',
        'pr_url',
        'pr_status',
        'unified_diff',
        'files_changed',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pr_status' => PrStatus::class,
            'files_changed' => 'array',
            'pr_number' => 'integer',
        ];
    }

    /** @return BelongsTo<PipelineTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(PipelineTask::class, 'pipeline_task_id');
    }
}
