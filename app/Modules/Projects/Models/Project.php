<?php

namespace App\Modules\Projects\Models;

use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Iam\Models\UserModel;
use App\Modules\Projects\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuids;

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
}
