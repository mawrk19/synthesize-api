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
            'token' => $this->token,
            'url_path' => '/review/'.$this->token,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'allow_comment' => $this->allow_comment,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
