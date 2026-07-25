<?php

namespace App\Modules\Collaboration\Models;

use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Iam\Models\UserModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    use HasUuids;

    protected $table = 'document_versions';

    protected $fillable = [
        'srs_document_id',
        'version_number',
        'generated_srs',
        'created_by',
    ];

    /** @return BelongsTo<SrsDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SrsDocument::class, 'srs_document_id');
    }

    /** @return BelongsTo<UserModel, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by');
    }
}
