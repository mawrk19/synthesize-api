<?php

namespace App\Modules\Collaboration\Http\Resources;

use App\Modules\Collaboration\Models\ReviewLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReviewLink */
class ReviewLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'pipeline_run_id' => $this->pipeline_run_id,
            'token' => $this->token,
            'url_path' => '/review/'.$this->token,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'allow_comment' => $this->allow_comment,
            'approval_status' => $this->approval_status?->value ?? 'pending',
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by_name' => $this->approved_by_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
