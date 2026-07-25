<?php

namespace App\Modules\Documents\Jobs;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Documents\Services\SrsGenerationService;
use App\Modules\Projects\Jobs\ExtractRequirementsJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateSrsDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public string $documentId,
    ) {}

    public function handle(SrsGenerationService $generationService): void
    {
        $document = SrsDocument::query()->find($this->documentId);

        if (! $document) {
            return;
        }

        $document->update([
            'status' => DocumentStatus::Processing,
            'error_message' => null,
        ]);

        $srs = $generationService->generate($document->title, $document->source_notes);

        $document->update([
            'status' => DocumentStatus::Completed,
            'generated_srs' => $srs,
            'error_message' => null,
        ]);

        ExtractRequirementsJob::dispatch($document->id);
    }

    public function failed(?Throwable $exception): void
    {
        $document = SrsDocument::query()->find($this->documentId);

        if (! $document) {
            return;
        }

        Log::error('SRS generation job failed', [
            'document_id' => $this->documentId,
            'message' => $exception?->getMessage(),
        ]);

        $document->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $exception?->getMessage() ?? 'SRS generation failed.',
        ]);
    }
}
