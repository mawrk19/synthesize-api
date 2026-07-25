<?php

namespace App\Modules\Analysis\Models;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchemaArtifact extends Model
{
    use HasUuids;

    protected $table = 'schema_artifacts';

    protected $fillable = [
        'project_id',
        'srs_document_id',
        'ddl_sql',
        'openapi_json',
        'status',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
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
}
