<?php

namespace App\Modules\Analysis\Services;

use App\Modules\Analysis\Jobs\GenerateSchemaJob;
use App\Modules\Analysis\Jobs\RunGapAnalysisJob;
use App\Modules\Analysis\Jobs\RunRequirementValidatorJob;
use App\Modules\Analysis\Models\AnalysisRun;
use App\Modules\Analysis\Models\SchemaArtifact;
use App\Modules\Core\Services\AiCompletionService;
use App\Modules\Diagrams\Models\Diagram;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Projects\Models\Requirement;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AnalysisService
{
    public function __construct(
        private readonly AiCompletionService $ai,
        private readonly SchemaContextBuilder $schemaContext,
    ) {}

    public function startGap(SrsDocument $document): AnalysisRun
    {
        $run = AnalysisRun::query()->create([
            'project_id' => $document->project_id,
            'srs_document_id' => $document->id,
            'mode' => 'gap',
            'status' => DocumentStatus::Pending,
        ]);

        RunGapAnalysisJob::dispatch($run->id);

        return $run;
    }

    public function startValidator(SrsDocument $document): AnalysisRun
    {
        $run = AnalysisRun::query()->create([
            'project_id' => $document->project_id,
            'srs_document_id' => $document->id,
            'mode' => 'validator',
            'status' => DocumentStatus::Pending,
        ]);

        RunRequirementValidatorJob::dispatch($run->id);

        return $run;
    }

    public function startSchema(SrsDocument $document): SchemaArtifact
    {
        $artifact = SchemaArtifact::query()->create([
            'project_id' => $document->project_id,
            'srs_document_id' => $document->id,
            'status' => DocumentStatus::Pending,
        ]);

        GenerateSchemaJob::dispatch($artifact->id);

        return $artifact;
    }

    /** @return Collection<int, AnalysisRun> */
    public function listRunsForDocument(string $documentId): Collection
    {
        return AnalysisRun::query()
            ->where('srs_document_id', $documentId)
            ->latest()
            ->get();
    }

    /** @return Collection<int, SchemaArtifact> */
    public function listSchemasForDocument(string $documentId): Collection
    {
        return SchemaArtifact::query()
            ->where('srs_document_id', $documentId)
            ->latest()
            ->get();
    }

    public function findRun(string $id): ?AnalysisRun
    {
        return AnalysisRun::query()->find($id);
    }

    public function findSchema(string $id): ?SchemaArtifact
    {
        return SchemaArtifact::query()->find($id);
    }

    /** @return array{markdown: string, findings: array} */
    public function runGapAnalysis(SrsDocument $document): array
    {
        $requirements = Requirement::query()
            ->where('srs_document_id', $document->id)
            ->get()
            ->map(fn (Requirement $r) => "{$r->code} [{$r->type}]: {$r->body}")
            ->implode("\n");

        $srs = (string) $document->generated_srs;

        if (! $this->ai->isConfigured()) {
            return $this->gapFallback($srs);
        }

        $system = <<<'PROMPT'
You are a Lead System Analyst performing gap & edge-case analysis (/gap mode).
Analyze the SRS and structured requirements for logical holes, missing flows, security risks, and unhandled exceptions.
Return ONLY valid JSON:
{
  "markdown": "full markdown report",
  "findings": {
    "critical": [{"area":"","gap":"","recommendation":""}],
    "high": [],
    "medium": []
  }
}
PROMPT;

        $content = $this->ai->complete($system, "SRS:\n{$srs}\n\nRequirements:\n{$requirements}");
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($content)) ?? $content;
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return [
                'markdown' => $content,
                'findings' => ['critical' => [], 'high' => [], 'medium' => []],
            ];
        }

        return [
            'markdown' => (string) ($decoded['markdown'] ?? $content),
            'findings' => [
                'critical' => $decoded['findings']['critical'] ?? [],
                'high' => $decoded['findings']['high'] ?? [],
                'medium' => $decoded['findings']['medium'] ?? [],
            ],
        ];
    }

    /** @return array{markdown: string, findings: array, flags: list<array{code: string, questions: list<string>}>} */
    public function runValidator(SrsDocument $document): array
    {
        $requirements = Requirement::query()
            ->where('srs_document_id', $document->id)
            ->get();

        if (! $this->ai->isConfigured()) {
            return $this->validatorFallback($requirements);
        }

        $list = $requirements->map(fn (Requirement $r) => "{$r->code}: {$r->body}")->implode("\n");

        $system = <<<'PROMPT'
You are a Requirement Validator. Flag ambiguous or subjective requirements.
Return ONLY valid JSON:
{
  "markdown": "summary markdown",
  "flags": [{"code":"NFR-001","questions":["Do you mean sub-200ms latency?","Or high throughput?"]}]
}
Focus on vague terms like fast, secure, user-friendly, scalable without metrics.
PROMPT;

        $content = $this->ai->complete($system, $list);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($content)) ?? $content;
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return $this->validatorFallback($requirements);
        }

        $flags = $decoded['flags'] ?? [];

        foreach ($flags as $flag) {
            $code = $flag['code'] ?? null;
            if (! $code) {
                continue;
            }
            $req = $requirements->firstWhere('code', $code);
            if ($req) {
                $req->update(['validation_flags' => $flag['questions'] ?? []]);
            }
        }

        return [
            'markdown' => (string) ($decoded['markdown'] ?? ''),
            'findings' => ['flags' => $flags],
            'flags' => $flags,
        ];
    }

    /** @return array{ddl_sql: string, openapi_json: string} */
    public function generateSchema(SrsDocument $document): array
    {
        $requirements = $this->requirementsForDocument($document);

        if (! $this->ai->isConfigured()) {
            return $this->schemaContext->fallbackForDocument($document);
        }

        $system = $this->schemaContext->buildSystemPrompt($document);
        $user = $this->schemaContext->buildUserPrompt($document, $requirements);

        $content = $this->ai->complete($system, $user, ['timeout' => 180]);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($content)) ?? $content;
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return $this->schemaContext->fallbackForDocument($document);
        }

        $ddl = trim((string) ($decoded['ddl_sql'] ?? ''));
        $openapi = $decoded['openapi_json'] ?? '{}';
        if (is_array($openapi)) {
            $openapi = json_encode($openapi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if ($ddl === '' || trim((string) $openapi) === '' || trim((string) $openapi) === '{}') {
            return $this->schemaContext->fallbackForDocument($document);
        }

        return [
            'ddl_sql' => $ddl,
            'openapi_json' => (string) $openapi,
        ];
    }

    public function buildPrdExport(SrsDocument $document): string
    {
        $parts = [
            "# Product Requirements Document: {$document->title}",
            '',
            '_Generated by Synthesize for stakeholder / product handoff._',
            '',
            '## Specification',
            '',
            filled($document->generated_srs)
                ? trim((string) $document->generated_srs)
                : '_SRS content is not available yet._',
        ];

        $requirements = $this->requirementsForDocument($document);
        $parts[] = '';
        $parts[] = $this->formatRequirementsMarkdown($requirements);

        $gap = $this->latestCompletedGap($document);
        if ($gap?->result_markdown) {
            $parts[] = '';
            $parts[] = '## Gap Analysis';
            $parts[] = '';
            $parts[] = trim((string) $gap->result_markdown);
        }

        $schema = $this->latestCompletedSchema($document);
        $parts[] = '';
        $parts[] = $this->formatSchemaMarkdown($schema);

        $diagrams = $this->diagramsForDocument($document);
        if ($diagrams->isNotEmpty()) {
            $parts[] = '';
            $parts[] = $this->formatDiagramsMarkdown($diagrams);
        }

        return rtrim(implode("\n", $parts))."\n";
    }

    public function buildReadmeExport(SrsDocument $document): string
    {
        $srs = trim((string) ($document->generated_srs ?? ''));
        $overview = $this->extractOverview($srs) ?: 'Developer handoff package generated by Synthesize.';
        $requirements = $this->requirementsForDocument($document);
        $schema = $this->latestCompletedSchema($document);
        $gap = $this->latestCompletedGap($document);
        $diagrams = $this->diagramsForDocument($document);

        $parts = [
            "# {$document->title}",
            '',
            '## Overview',
            '',
            $overview,
            '',
            '## How to use this handoff',
            '',
            '1. Read **Functional / Non-Functional Requirements** and **User Stories** below.',
            '2. Implement against the **Database Schema** and **API Contract** when present.',
            '3. Use **Diagrams** for sequence / data-model context.',
            '4. Treat **Full Specification** as the source of truth if anything conflicts.',
            '',
            $this->formatRequirementsMarkdown($requirements),
            '',
            $this->formatSchemaMarkdown($schema),
            '',
            $this->formatOpenApiMarkdown($schema),
        ];

        if ($diagrams->isNotEmpty()) {
            $parts[] = '';
            $parts[] = $this->formatDiagramsMarkdown($diagrams);
        }

        if ($gap?->result_markdown) {
            $parts[] = '';
            $parts[] = '## Known Gaps';
            $parts[] = '';
            $parts[] = trim((string) $gap->result_markdown);
        }

        $parts[] = '';
        $parts[] = '## Full Specification';
        $parts[] = '';
        $parts[] = $srs !== ''
            ? $srs
            : '_No generated SRS markdown is available for this document._';

        if (filled($document->source_notes)) {
            $parts[] = '';
            $parts[] = '## Source Notes';
            $parts[] = '';
            $parts[] = trim((string) $document->source_notes);
        }

        $missing = $this->missingArtifactHints($requirements, $schema, $diagrams);
        if ($missing !== []) {
            $parts[] = '';
            $parts[] = '## Missing artifacts';
            $parts[] = '';
            foreach ($missing as $hint) {
                $parts[] = "- {$hint}";
            }
        }

        return rtrim(implode("\n", $parts))."\n";
    }

    /** @return Collection<int, Requirement> */
    private function requirementsForDocument(SrsDocument $document): Collection
    {
        $rows = Requirement::query()
            ->where('srs_document_id', $document->id)
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        if ($rows->isNotEmpty()) {
            return $rows;
        }

        // Fallback: parse FR/NFR/stories directly from SRS markdown when extraction never ran.
        return $this->parseRequirementsFromSrs($document);
    }

    /** @return Collection<int, Requirement> */
    private function parseRequirementsFromSrs(SrsDocument $document): Collection
    {
        $markdown = (string) ($document->generated_srs ?? '');
        if ($markdown === '' || blank($document->project_id)) {
            return collect();
        }

        $parsed = collect();

        foreach ($this->parseTableRequirementRows($markdown, 'FR') as $row) {
            $parsed->push(new Requirement([
                'project_id' => $document->project_id,
                'srs_document_id' => $document->id,
                'type' => 'fr',
                'code' => $row['code'],
                'title' => mb_substr($row['body'], 0, 255),
                'body' => $row['body'],
                'priority' => $row['priority'],
            ]));
        }

        foreach ($this->parseTableRequirementRows($markdown, 'NFR') as $row) {
            $parsed->push(new Requirement([
                'project_id' => $document->project_id,
                'srs_document_id' => $document->id,
                'type' => 'nfr',
                'code' => $row['code'],
                'title' => mb_substr($row['body'], 0, 255),
                'body' => $row['body'],
                'priority' => $row['priority'],
            ]));
        }

        $storyIndex = 1;
        foreach ($this->parseGherkinBlocks($markdown) as $block) {
            $parsed->push(new Requirement([
                'project_id' => $document->project_id,
                'srs_document_id' => $document->id,
                'type' => 'story',
                'code' => sprintf('US-%03d', $storyIndex++),
                'title' => $block['title'],
                'body' => $block['title'],
                'gherkin' => $block['gherkin'],
            ]));
        }

        return $parsed;
    }

    /**
     * @return list<array{code: string, body: string, priority: ?string}>
     */
    private function parseTableRequirementRows(string $markdown, string $prefix): array
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

            if (strcasecmp($body, 'Requirement') === 0) {
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

    /** @param  Collection<int, Requirement>  $requirements */
    private function formatRequirementsMarkdown(Collection $requirements): string
    {
        $fr = $requirements->where('type', 'fr')->values();
        $nfr = $requirements->where('type', 'nfr')->values();
        $stories = $requirements->where('type', 'story')->values();

        $parts = ['## Functional Requirements', ''];
        if ($fr->isEmpty()) {
            $parts[] = '_No functional requirements extracted. See Full Specification below._';
        } else {
            foreach ($fr as $r) {
                $priority = $r->priority ? " ({$r->priority})" : '';
                $parts[] = "- **{$r->code}**{$priority}: {$r->body}";
            }
        }

        $parts[] = '';
        $parts[] = '## Non-Functional Requirements';
        $parts[] = '';
        if ($nfr->isEmpty()) {
            $parts[] = '_No non-functional requirements extracted. See Full Specification below._';
        } else {
            foreach ($nfr as $r) {
                $parts[] = "- **{$r->code}**: {$r->body}";
            }
        }

        $parts[] = '';
        $parts[] = '## User Stories';
        $parts[] = '';
        if ($stories->isEmpty()) {
            $parts[] = '_No Gherkin user stories were found in the SRS._';
        } else {
            foreach ($stories as $r) {
                $parts[] = "### {$r->code} — {$r->title}";
                $parts[] = '';
                if (filled($r->gherkin)) {
                    $parts[] = '```gherkin';
                    $parts[] = trim((string) $r->gherkin);
                    $parts[] = '```';
                } else {
                    $parts[] = (string) ($r->body ?: $r->title);
                }
                $parts[] = '';
            }
        }

        return rtrim(implode("\n", $parts));
    }

    private function formatSchemaMarkdown(?SchemaArtifact $schema): string
    {
        $parts = ['## Database Schema', ''];

        if ($schema && filled($schema->ddl_sql)) {
            $parts[] = '```sql';
            $parts[] = trim((string) $schema->ddl_sql);
            $parts[] = '```';
        } else {
            $parts[] = '_No completed schema artifact yet. In Synthesize, open this document → **Schema** → **Generate DDL + OpenAPI**._';
        }

        return implode("\n", $parts);
    }

    private function formatOpenApiMarkdown(?SchemaArtifact $schema): string
    {
        $parts = ['## API Contract', ''];

        if ($schema && filled($schema->openapi_json)) {
            $json = trim((string) $schema->openapi_json);
            // Pretty-print if stored as compact JSON.
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $json = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
            $parts[] = '```json';
            $parts[] = $json;
            $parts[] = '```';
        } else {
            $parts[] = '_No OpenAPI artifact yet. Generate schema in Synthesize to include request/response DTOs here._';
        }

        return implode("\n", $parts);
    }

    /** @param  Collection<int, Diagram>  $diagrams */
    private function formatDiagramsMarkdown(Collection $diagrams): string
    {
        $parts = ['## Diagrams', ''];

        foreach ($diagrams as $diagram) {
            $type = $diagram->type->value;
            $parts[] = "### {$diagram->title} (`{$type}`)";
            $parts[] = '';
            if ($diagram->status === DocumentStatus::Completed && filled($diagram->mermaid_source)) {
                $parts[] = '```mermaid';
                $parts[] = trim((string) $diagram->mermaid_source);
                $parts[] = '```';
            } elseif ($diagram->status === DocumentStatus::Failed) {
                $parts[] = '_Generation failed'.($diagram->error_message ? ': '.$diagram->error_message : '.').'_';
            } else {
                $parts[] = '_Still generating (status: '.$diagram->status->value.')._';
            }
            $parts[] = '';
        }

        return rtrim(implode("\n", $parts));
    }

    private function extractOverview(string $srs): ?string
    {
        if ($srs === '') {
            return null;
        }

        if (preg_match('/##\s+Overview\s*\n+(.*?)(?=\n##\s|\z)/si', $srs, $match)) {
            $text = trim($match[1]);
            // Drop leading tables / empty noise.
            $text = preg_replace('/^\|.*/m', '', $text) ?? $text;
            $text = trim($text);
            if ($text !== '') {
                return $text;
            }
        }

        // First non-heading paragraph.
        $lines = preg_split('/\R/', $srs) ?: [];
        $buffer = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                if ($buffer !== []) {
                    break;
                }
                continue;
            }
            $buffer[] = $trim;
            if (strlen(implode(' ', $buffer)) > 400) {
                break;
            }
        }

        if ($buffer === []) {
            return null;
        }

        return Str::limit(implode(' ', $buffer), 600);
    }

    private function latestCompletedSchema(SrsDocument $document): ?SchemaArtifact
    {
        return SchemaArtifact::query()
            ->where('srs_document_id', $document->id)
            ->where('status', DocumentStatus::Completed)
            ->latest()
            ->first();
    }

    private function latestCompletedGap(SrsDocument $document): ?AnalysisRun
    {
        return AnalysisRun::query()
            ->where('srs_document_id', $document->id)
            ->where('mode', 'gap')
            ->where('status', DocumentStatus::Completed)
            ->latest()
            ->first();
    }

    /** @return Collection<int, Diagram> */
    private function diagramsForDocument(SrsDocument $document): Collection
    {
        return Diagram::query()
            ->where('srs_document_id', $document->id)
            ->orderBy('type')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @param  Collection<int, Requirement>  $requirements
     * @param  Collection<int, Diagram>  $diagrams
     * @return list<string>
     */
    private function missingArtifactHints(Collection $requirements, ?SchemaArtifact $schema, Collection $diagrams): array
    {
        $hints = [];

        if ($requirements->where('type', 'story')->isEmpty()) {
            $hints[] = 'User stories / Gherkin — add ### Title sections with gherkin code fences in the SRS, or regenerate with story coverage.';
        }

        if (! $schema || blank($schema->ddl_sql)) {
            $hints[] = 'Database DDL — run **Schema → Generate DDL + OpenAPI** on this document.';
        }

        if (! $schema || blank($schema->openapi_json)) {
            $hints[] = 'OpenAPI contract — run **Schema → Generate DDL + OpenAPI** on this document.';
        }

        if ($diagrams->isEmpty()) {
            $hints[] = 'Diagrams — run **Diagrams → Generate Sequence + ERD** on this document.';
        }

        return $hints;
    }

    /** @return array{markdown: string, findings: array} */
    private function gapFallback(string $srs): array
    {
        $findings = [
            'critical' => [],
            'high' => [],
            'medium' => [],
        ];

        $lower = strtolower($srs);
        if (str_contains($lower, 'sign') && str_contains($lower, 'up') && ! str_contains($lower, 'password recovery') && ! str_contains($lower, 'reset')) {
            $findings['critical'][] = [
                'area' => 'Auth',
                'gap' => 'Sign-up flow defined without password recovery',
                'recommendation' => 'Add FR for password reset token expiry and delivery channel',
            ];
        }

        if (! str_contains($lower, 'audit')) {
            $findings['medium'][] = [
                'area' => 'Compliance',
                'gap' => 'No explicit audit logging requirement detected',
                'recommendation' => 'Confirm whether audit trails are required for mutations',
            ];
        }

        $markdown = "## Gap Analysis (local mode)\n\n";
        foreach (['critical', 'high', 'medium'] as $sev) {
            $markdown .= "### ".ucfirst($sev)."\n\n";
            foreach ($findings[$sev] as $f) {
                $markdown .= "- **{$f['area']}**: {$f['gap']} — *{$f['recommendation']}*\n";
            }
            if ($findings[$sev] === []) {
                $markdown .= "- None detected by heuristic scanner.\n";
            }
            $markdown .= "\n";
        }

        return ['markdown' => $markdown, 'findings' => $findings];
    }

    /**
     * @param  Collection<int, Requirement>  $requirements
     * @return array{markdown: string, findings: array, flags: list<array{code: string, questions: list<string>}>}
     */
    private function validatorFallback(Collection $requirements): array
    {
        $flags = [];
        $ambiguous = '/\b(fast|secure|user-friendly|scalable|performant|easy|quickly|reliable)\b/i';

        foreach ($requirements as $req) {
            if (preg_match($ambiguous, (string) $req->body)) {
                $questions = [
                    'This requirement uses subjective language. Can you define measurable criteria?',
                    'What specific thresholds (latency, throughput, availability %) are expected?',
                ];
                $req->update(['validation_flags' => $questions]);
                $flags[] = ['code' => $req->code, 'questions' => $questions];
            }
        }

        $markdown = "## Requirement Validation\n\nFlagged ".count($flags)." ambiguous requirement(s).\n";

        return [
            'markdown' => $markdown,
            'findings' => ['flags' => $flags],
            'flags' => $flags,
        ];
    }

}
