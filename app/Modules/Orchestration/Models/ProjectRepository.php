<?php

namespace App\Modules\Orchestration\Models;

use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class ProjectRepository extends Model
{
    use HasUuids;

    protected $table = 'project_repositories';

    protected $fillable = [
        'project_id',
        'provider',
        'owner',
        'repo',
        'default_branch',
        'encrypted_token',
        'base_path',
    ];

    protected $hidden = [
        'encrypted_token',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function setToken(?string $plainToken): void
    {
        $this->encrypted_token = blank($plainToken)
            ? null
            : Crypt::encryptString($plainToken);
    }

    public function getDecryptedToken(): ?string
    {
        if (blank($this->encrypted_token)) {
            return null;
        }

        return Crypt::decryptString($this->encrypted_token);
    }

    public function hasToken(): bool
    {
        return filled($this->encrypted_token) || filled(config('services.github.default_token'));
    }
}
