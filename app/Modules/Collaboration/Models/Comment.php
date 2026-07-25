<?php

namespace App\Modules\Collaboration\Models;

use App\Modules\Iam\Models\UserModel;
use App\Modules\Projects\Models\Requirement;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comment extends Model
{
    use HasUuids;

    protected $table = 'comments';

    protected $fillable = [
        'requirement_id',
        'user_id',
        'guest_name',
        'body',
        'resolved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Requirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class, 'requirement_id');
    }

    /** @return BelongsTo<UserModel, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }
}
