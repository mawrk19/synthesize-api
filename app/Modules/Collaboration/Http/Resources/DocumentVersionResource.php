<?php

namespace App\Modules\Collaboration\Http\Resources;

use App\Modules\Collaboration\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentVersion */
class DocumentVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'srs_document_id' => $this->srs_document_id,
            'version_number' => $this->version_number,
            'generated_srs' => $this->generated_srs,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
