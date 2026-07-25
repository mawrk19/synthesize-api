<?php

namespace App\Modules\Projects\Models;

use App\Modules\Documents\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContextFile extends Model
{
    use HasUuids;

    protected $table = 'context_files';

    protected $fillable = [
        'project_id',
        'filename',
        'mime_type',
        'storage_path',
        'extracted_text',
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
}
