<?php

namespace App\Modules\Analysis\Http\Resources;

use App\Modules\Analysis\Models\AnalysisRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AnalysisRun */
class AnalysisRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'srs_document_id' => $this->srs_document_id,
            'mode' => $this->mode,
            'result_markdown' => $this->result_markdown,
            'findings' => $this->findings,
            'status' => $this->status->value,
            'error_message' => \App\Support\ClientDebug::publicError($this->error_message),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
