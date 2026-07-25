<?php

namespace App\Modules\Projects\Models;

use App\Modules\Documents\Models\SrsDocument;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Requirement extends Model
{
    use HasUuids;

    protected $table = 'requirements';

    protected $fillable = [
        'project_id',
        'srs_document_id',
        'type',
        'code',
        'title',
        'body',
        'priority',
        'gherkin',
        'validation_flags',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'validation_flags' => 'array',
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

    /** @return HasMany<\App\Modules\Collaboration\Models\Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(\App\Modules\Collaboration\Models\Comment::class, 'requirement_id');
    }
}
