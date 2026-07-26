<?php

namespace App\Modules\Collaboration\Models;

use App\Modules\Orchestration\Enums\ReviewApprovalStatus;
use App\Modules\Orchestration\Models\PipelineRun;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReviewLink extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'review_links';

    protected $fillable = [
        'project_id',
        'pipeline_run_id',
        'token',
        'expires_at',
        'allow_comment',
        'approval_status',
        'approved_at',
        'approved_by_name',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'allow_comment' => 'boolean',
            'approval_status' => ReviewApprovalStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return BelongsTo<PipelineRun, $this> */
    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class, 'pipeline_run_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
