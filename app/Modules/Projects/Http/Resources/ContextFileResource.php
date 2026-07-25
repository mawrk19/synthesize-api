<?php

namespace App\Modules\Projects\Http\Resources;

use App\Modules\Projects\Models\ContextFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContextFile */
class ContextFileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'status' => $this->status->value,
            'extracted_text' => $this->extracted_text,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
