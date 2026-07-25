<?php

namespace App\Modules\Projects\Jobs;

use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Projects\Models\Requirement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractRequirementsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public string $documentId,
    ) {}

    public function handle(): void
    {
        $document = SrsDocument::query()->find($this->documentId);

        if (! $document || blank($document->generated_srs) || blank($document->project_id)) {
            return;
        }

        Requirement::query()
            ->where('srs_document_id', $document->id)
            ->delete();

        $markdown = (string) $document->generated_srs;

        foreach ($this->parseTableRequirements($markdown, 'FR') as $row) {
            Requirement::query()->create([
                'project_id' => $document->project_id,
                'srs_document_id' => $document->id,
                'type' => 'fr',
                'code' => $row['code'],
                'title' => mb_substr($row['body'], 0, 255),
                'body' => $row['body'],
                'priority' => $row['priority'],
            ]);
        }

        foreach ($this->parseTableRequirements($markdown, 'NFR') as $row) {
            Requirement::query()->create([
                'project_id' => $document->project_id,
                'srs_document_id' => $document->id,
                'type' => 'nfr',
                'code' => $row['code'],
                'title' => mb_substr($row['body'], 0, 255),
                'body' => $row['body'],
                'priority' => $row['priority'],
            ]);
        }

        $storyIndex = 1;
        foreach ($this->parseGherkinBlocks($markdown) as $block) {
            $code = sprintf('US-%03d', $storyIndex++);
            Requirement::query()->create([
                'project_id' => $document->project_id,
                'srs_document_id' => $document->id,
                'type' => 'story',
                'code' => $code,
                'title' => $block['title'],
                'body' => $block['title'],
                'gherkin' => $block['gherkin'],
            ]);
        }
    }

    /**
     * @return list<array{code: string, body: string, priority: ?string}>
     */
    private function parseTableRequirements(string $markdown, string $prefix): array
    {
        $rows = [];
        $pattern = '/\|\s*('.$prefix.'-\d+)\s*\|\s*([^|]+)\s*\|(?:\s*([^|]+)\s*\|)?/i';

        if (! preg_match_all($pattern, $markdown, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $code = strtoupper(trim($match[1]));
            $body = trim($match[2]);
            $priority = isset($match[3]) ? trim($match[3]) : null;

            if (strcasecmp($body, 'Requirement') === 0 || strcasecmp($code, $prefix.'-XXX') === 0) {
                continue;
            }

            if (preg_match('/^-+$/', $body) || preg_match('/^id$/i', $code)) {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'body' => $body,
                'priority' => $priority && ! preg_match('/^-+$/', $priority) ? $priority : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{title: string, gherkin: string}>
     */
    private function parseGherkinBlocks(string $markdown): array
    {
        $blocks = [];

        if (preg_match_all('/###\s+([^\n]+)\n+```gherkin\n(.*?)```/si', $markdown, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $blocks[] = [
                    'title' => trim($match[1]),
                    'gherkin' => trim($match[2]),
                ];
            }
        }

        return $blocks;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Extract requirements job failed', [
            'document_id' => $this->documentId,
            'message' => $exception?->getMessage(),
        ]);
    }
}
