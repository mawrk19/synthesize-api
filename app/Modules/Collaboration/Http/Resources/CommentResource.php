<?php

namespace App\Modules\Collaboration\Http\Resources;

use App\Modules\Collaboration\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Comment */
class CommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $fullName = trim(($this->user?->first_name ?? '').' '.($this->user?->last_name ?? ''));

        return [
            'id' => $this->id,
            'requirement_id' => $this->requirement_id,
            'user_id' => $this->user_id,
            'guest_name' => $this->guest_name,
            'author_name' => $this->guest_name
                ?: ($fullName !== '' ? $fullName : null)
                ?: $this->user?->username
                ?: $this->user?->email,
            'body' => $this->body,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
