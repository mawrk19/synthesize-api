<?php

namespace App\Modules\Analysis\Http\Resources;

use App\Modules\Analysis\Models\SchemaArtifact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SchemaArtifact */
class SchemaArtifactResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'srs_document_id' => $this->srs_document_id,
            'ddl_sql' => $this->ddl_sql,
            'openapi_json' => $this->openapi_json,
            'status' => $this->status->value,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
