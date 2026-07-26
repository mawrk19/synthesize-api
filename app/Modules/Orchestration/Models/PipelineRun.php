<?php

namespace App\Modules\Orchestration\Models;

use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Iam\Models\UserModel;
use App\Modules\Orchestration\Enums\AgentRole;
use App\Modules\Orchestration\Enums\PipelineRunStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PipelineRun extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'pipeline_runs';

    protected $fillable = [
        'project_id',
        'srs_document_id',
        'status',
        'current_phase',
        'approved_at',
        'approved_by_user_id',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PipelineRunStatus::class,
            'current_phase' => AgentRole::class,
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return BelongsTo<SrsDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SrsDocument::class, 'srs_document_id');
    }

    /** @return BelongsTo<UserModel, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'approved_by_user_id');
    }

    /** @return HasMany<PipelineTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(PipelineTask::class, 'pipeline_run_id')->orderBy('sort_order');
    }
}
