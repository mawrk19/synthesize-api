<?php

namespace App\Modules\Orchestration\Http\Resources;

use App\Modules\Orchestration\Models\ProjectRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProjectRepository */
class ProjectRepositoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'provider' => $this->provider,
            'owner' => $this->owner,
            'repo' => $this->repo,
            'default_branch' => $this->default_branch,
            'base_path' => $this->base_path,
            'has_token' => $this->hasToken(),
            'initialization_warning' => $this->when(
                filled($this->initialization_warning ?? null),
                \App\Support\ClientDebug::publicError((string) $this->initialization_warning),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
