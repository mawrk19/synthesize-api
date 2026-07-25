<?php

namespace App\Modules\Diagrams\Jobs;

use App\Modules\Diagrams\Models\Diagram;
use App\Modules\Diagrams\Services\DiagramService;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\SrsDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateDiagramJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public string $diagramId,
    ) {}

    public function handle(DiagramService $diagramService): void
    {
        $diagram = Diagram::query()->find($this->diagramId);

        if (! $diagram) {
            return;
        }

        $diagram->update([
            'status' => DocumentStatus::Processing,
            'error_message' => null,
        ]);

        $srsContent = '';
        if ($diagram->srs_document_id) {
            $doc = SrsDocument::query()->find($diagram->srs_document_id);
            $srsContent = (string) ($doc?->generated_srs ?: $doc?->source_notes);
        }

        if (blank($srsContent)) {
            $latest = SrsDocument::query()
                ->where('project_id', $diagram->project_id)
                ->where('status', DocumentStatus::Completed)
                ->latest()
                ->first();
            $srsContent = (string) ($latest?->generated_srs ?: 'No SRS available yet.');
        }

        $mermaid = $diagramService->generateMermaid($diagram, $srsContent);

        $diagram->update([
            'mermaid_source' => $mermaid,
            'status' => DocumentStatus::Completed,
            'error_message' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $diagram = Diagram::query()->find($this->diagramId);

        if (! $diagram) {
            return;
        }

        Log::error('Diagram generation failed', [
            'diagram_id' => $this->diagramId,
            'message' => $exception?->getMessage(),
        ]);

        $diagram->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $exception?->getMessage() ?? 'Diagram generation failed.',
        ]);
    }
}
