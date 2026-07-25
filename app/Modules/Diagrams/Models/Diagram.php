<?php

namespace App\Modules\Diagrams\Models;

use App\Modules\Diagrams\Enums\DiagramType;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Diagram extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'diagrams';

    protected $fillable = [
        'project_id',
        'srs_document_id',
        'type',
        'title',
        'mermaid_source',
        'status',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DiagramType::class,
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
