<?php

namespace App\Modules\Analysis\Jobs;

use App\Modules\Analysis\Models\AnalysisRun;
use App\Modules\Analysis\Services\AnalysisService;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\SrsDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunGapAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public string $runId) {}

    public function handle(AnalysisService $analysisService): void
    {
        $run = AnalysisRun::query()->find($this->runId);
        if (! $run) {
            return;
        }

        $document = SrsDocument::query()->find($run->srs_document_id);
        if (! $document) {
            $run->update(['status' => DocumentStatus::Failed, 'error_message' => 'Document not found']);

            return;
        }

        $run->update(['status' => DocumentStatus::Processing, 'error_message' => null]);
        $result = $analysisService->runGapAnalysis($document);

        $run->update([
            'result_markdown' => $result['markdown'],
            'findings' => $result['findings'],
            'status' => DocumentStatus::Completed,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $run = AnalysisRun::query()->find($this->runId);
        if (! $run) {
            return;
        }

        Log::error('Gap analysis failed', ['run_id' => $this->runId, 'message' => $exception?->getMessage()]);
        $run->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $exception?->getMessage() ?? 'Gap analysis failed.',
        ]);
    }
}
