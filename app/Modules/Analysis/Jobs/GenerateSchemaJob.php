<?php

namespace App\Modules\Analysis\Jobs;

use App\Modules\Analysis\Models\SchemaArtifact;
use App\Modules\Analysis\Services\AnalysisService;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\SrsDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateSchemaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public string $artifactId) {}

    public function handle(AnalysisService $analysisService): void
    {
        $artifact = SchemaArtifact::query()->find($this->artifactId);
        if (! $artifact) {
            return;
        }

        $document = SrsDocument::query()->find($artifact->srs_document_id);
        if (! $document) {
            $artifact->update(['status' => DocumentStatus::Failed, 'error_message' => 'Document not found']);

            return;
        }

        $artifact->update(['status' => DocumentStatus::Processing, 'error_message' => null]);
        $result = $analysisService->generateSchema($document);

        $artifact->update([
            'ddl_sql' => $result['ddl_sql'],
            'openapi_json' => $result['openapi_json'],
            'status' => DocumentStatus::Completed,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $artifact = SchemaArtifact::query()->find($this->artifactId);
        if (! $artifact) {
            return;
        }

        Log::error('Schema generation failed', ['artifact_id' => $this->artifactId, 'message' => $exception?->getMessage()]);
        $artifact->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $exception?->getMessage() ?? 'Schema generation failed.',
        ]);
    }
}
