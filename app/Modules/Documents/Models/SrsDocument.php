<?php

namespace App\Modules\Documents\Models;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Iam\Models\UserModel;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SrsDocument extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'srs_documents';

    protected $fillable = [
        'user_id',
        'project_id',
        'title',
        'source_notes',
        'source_filename',
        'status',
        'generated_srs',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
        ];
    }

    /** @return BelongsTo<UserModel, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return HasMany<\App\Modules\Collaboration\Models\DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(\App\Modules\Collaboration\Models\DocumentVersion::class, 'srs_document_id');
    }
}
