<?php

namespace App\Modules\Analysis\Models;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AnalysisRun extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'analysis_runs';

    protected $fillable = [
        'project_id',
        'srs_document_id',
        'mode',
        'result_markdown',
        'findings',
        'status',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'findings' => 'array',
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
