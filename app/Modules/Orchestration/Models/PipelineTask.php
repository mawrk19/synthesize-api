<?php

namespace App\Modules\Orchestration\Models;

use App\Modules\Orchestration\Enums\AgentRole;
use App\Modules\Orchestration\Enums\PipelineTaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Requirement;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PipelineTask extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'pipeline_tasks';

    protected $fillable = [
        'pipeline_run_id',
        'project_id',
        'requirement_id',
        'parent_task_id',
        'sort_order',
        'title',
        'description',
        'agent_role',
        'status',
        'prompt_template',
        'files_hint',
        'depends_on_task_id',
        'attempts',
        'error_message',
        'audit_report',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'agent_role' => AgentRole::class,
            'status' => PipelineTaskStatus::class,
            'files_hint' => 'array',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<PipelineRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class, 'pipeline_run_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return BelongsTo<Requirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class, 'requirement_id');
    }

    /** @return BelongsTo<PipelineTask, $this> */
    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    /** @return BelongsTo<PipelineTask, $this> */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_task_id');
    }

    /** @return HasOne<TaskCodeChange, $this> */
    public function codeChange(): HasOne
    {
        return $this->hasOne(TaskCodeChange::class, 'pipeline_task_id');
    }
}
