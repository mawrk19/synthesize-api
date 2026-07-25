<?php

namespace App\Modules\Projects\Models;

use App\Modules\Projects\Enums\IntakeSourceType;
use App\Modules\Projects\Enums\IntakeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeSession extends Model
{
    use HasUuids;

    protected $table = 'intake_sessions';

    protected $fillable = [
        'project_id',
        'title',
        'source_type',
        'raw_content',
        'structured_draft',
        'status',
        'audio_path',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_type' => IntakeSourceType::class,
            'status' => IntakeStatus::class,
            'structured_draft' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
