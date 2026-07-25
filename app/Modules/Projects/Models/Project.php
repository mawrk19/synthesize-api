<?php

namespace App\Modules\Projects\Models;

use App\Modules\Analysis\Models\AnalysisRun;
use App\Modules\Analysis\Models\SchemaArtifact;
use App\Modules\Collaboration\Models\ReviewLink;
use App\Modules\Diagrams\Models\Diagram;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Iam\Models\UserModel;
use App\Modules\Projects\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'projects';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
        ];
    }

    /** @return BelongsTo<UserModel, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    /** @return HasMany<SrsDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(SrsDocument::class, 'project_id');
    }

    /** @return HasMany<ContextFile, $this> */
    public function contextFiles(): HasMany
    {
        return $this->hasMany(ContextFile::class, 'project_id');
    }

    /** @return HasMany<IntakeSession, $this> */
    public function intakeSessions(): HasMany
    {
        return $this->hasMany(IntakeSession::class, 'project_id');
    }

    /** @return HasMany<Requirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class, 'project_id');
    }

    /** @return HasMany<Diagram, $this> */
    public function diagrams(): HasMany
    {
        return $this->hasMany(Diagram::class, 'project_id');
    }

    /** @return HasMany<AnalysisRun, $this> */
    public function analysisRuns(): HasMany
    {
        return $this->hasMany(AnalysisRun::class, 'project_id');
    }

    /** @return HasMany<SchemaArtifact, $this> */
    public function schemaArtifacts(): HasMany
    {
        return $this->hasMany(SchemaArtifact::class, 'project_id');
    }

    /** @return HasMany<ReviewLink, $this> */
    public function reviewLinks(): HasMany
    {
        return $this->hasMany(ReviewLink::class, 'project_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (Project $project): void {
            if ($project->isForceDeleting()) {
                return;
            }

            $project->documents()->delete();
            $project->contextFiles()->delete();
            $project->intakeSessions()->delete();
            $project->requirements()->delete();
            $project->diagrams()->delete();
            $project->analysisRuns()->delete();
            $project->schemaArtifacts()->delete();
            $project->reviewLinks()->delete();
        });
    }
}
