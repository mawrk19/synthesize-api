<?php

namespace App\Modules\Orchestration\Services\Agents;

use App\Modules\Core\Services\AiCompletionService;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Orchestration\Enums\AgentRole;
use App\Modules\Orchestration\Enums\PipelineRunStatus;
use App\Modules\Orchestration\Enums\PipelineTaskStatus;
use App\Modules\Orchestration\Models\PipelineRun;
use App\Modules\Orchestration\Models\PipelineTask;
use App\Modules\Projects\Models\Requirement;
use App\Modules\Projects\Services\ContextFileService;
use Illuminate\Support\Facades\Log;
use Throwable;

class PlannerAgent
{
    public function __construct(
        private readonly AiCompletionService $ai,
        private readonly ContextFileService $contextFiles,
    ) {}

    public function execute(PipelineRun $run): void
    {
        $document = SrsDocument::query()->find($run->srs_document_id);
        if (! $document) {
            $run->update([
                'status' => PipelineRunStatus::Failed,
                'error_message' => 'SRS document not found.',
            ]);

            return;
        }

        $requirements = Requirement::query()
            ->where('project_id', $run->project_id)
            ->where('srs_document_id', $document->id)
            ->orderBy('code')
            ->get();

        $maxTasks = max(1, (int) config('services.pipeline.max_tasks_per_run', 10));

        try {
            $planned = $this->planTasks($document, $requirements, $maxTasks);
        } catch (Throwable $e) {
            Log::warning('PlannerAgent AI failed; using requirement fallback', [
                'run_id' => $run->id,
                'message' => $e->getMessage(),
            ]);
            $planned = $this->fallbackFromRequirements($requirements, $maxTasks);
        }

        if ($planned === []) {
            $run->update([
                'status' => PipelineRunStatus::Failed,
                'error_message' => 'Planner produced no tasks.',
            ]);

            return;
        }

        $requirementsByCode = $requirements->keyBy(fn (Requirement $r) => strtoupper((string) $r->code));

        foreach ($planned as $index => $item) {
            $requirementId = null;
            $code = strtoupper((string) ($item['requirement_code'] ?? ''));
            if ($code !== '' && $requirementsByCode->has($code)) {
                $requirementId = $requirementsByCode->get($code)->id;
            }

            $devTask = PipelineTask::query()->create([
                'pipeline_run_id' => $run->id,
                'project_id' => $run->project_id,
                'requirement_id' => $requirementId,
                'sort_order' => (int) ($item['sort_order'] ?? ($index + 1)),
                'title' => (string) ($item['title'] ?? 'Untitled task'),
                'description' => (string) ($item['description'] ?? ''),
                'agent_role' => AgentRole::Developer,
                'status' => PipelineTaskStatus::Pending,
                'prompt_template' => (string) ($item['prompt_template'] ?? ''),
                'files_hint' => $item['files_hint'] ?? [],
            ]);

            $tester = PipelineTask::query()->create([
                'pipeline_run_id' => $run->id,
                'project_id' => $run->project_id,
                'requirement_id' => $requirementId,
                'parent_task_id' => $devTask->id,
                'depends_on_task_id' => $devTask->id,
                'sort_order' => ((int) $devTask->sort_order * 10) + 1,
                'title' => 'Test: '.$devTask->title,
                'description' => 'Validate implementation against acceptance criteria / Gherkin.',
                'agent_role' => AgentRole::Tester,
                'status' => PipelineTaskStatus::Pending,
                'prompt_template' => 'Validate the developer PR against the requirement Gherkin stories.',
            ]);

            PipelineTask::query()->create([
                'pipeline_run_id' => $run->id,
                'project_id' => $run->project_id,
                'requirement_id' => $requirementId,
                'parent_task_id' => $devTask->id,
                'depends_on_task_id' => $tester->id,
                'sort_order' => ((int) $devTask->sort_order * 10) + 2,
                'title' => 'Review: '.$devTask->title,
                'description' => 'AI audit of the developer PR against the SRS requirement.',
                'agent_role' => AgentRole::Reviewer,
                'status' => PipelineTaskStatus::Pending,
                'prompt_template' => 'Audit the unified diff for correctness, security, and requirement coverage.',
            ]);
        }

        $run->update([
            'status' => PipelineRunStatus::AwaitingApproval,
            'current_phase' => AgentRole::Planner,
            'error_message' => null,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Requirement>  $requirements
     * @return list<array{title: string, description: string, requirement_code?: string, sort_order?: int, prompt_template?: string, files_hint?: list<string>}>
     */
    private function planTasks(SrsDocument $document, $requirements, int $maxTasks): array
    {
        $project = $document->project;
        $contextBlocks = $project
            ? $this->contextFiles->extractedBlocksForProject($project)
            : [];
        $reqBlock = $requirements->map(function (Requirement $r) {
            return "- [{$r->code}] {$r->title}\n  {$r->body}\n  Gherkin:\n{$r->gherkin}";
        })->implode("\n\n");

        $srs = mb_substr((string) $document->generated_srs, 0, 12000);
        $contextExcerpt = mb_substr(implode("\n\n", $contextBlocks), 0, 4000);

        $system = <<<'PROMPT'
You are the Planner agent for Synthesize. Break an SRS and requirements into concrete developer implementation tasks.
Respond with ONLY a JSON array (no markdown fences). Each item:
{
  "title": string,
  "description": string,
  "requirement_code": string|null,
  "sort_order": number,
  "prompt_template": string,
  "files_hint": string[]
}
Keep tasks small and implementable. Respect the max task count given by the user.
files_hint MUST list real relative paths the developer should open first (existing peer Form/DTO/Controller/Service files), not invented folders.
In prompt_template, tell the developer to match the project's existing coding standards (packages, validation imports, Lombok vs getters, layering).
PROMPT;

        $user = "Max tasks: {$maxTasks}\n\n# SRS\n{$srs}\n\n# Requirements\n{$reqBlock}\n\n# Context\n{$contextExcerpt}";

        $raw = $this->ai->complete($system, $user, [
            'temperature' => 0.2,
            'max_tokens' => 4096,
            'operation' => 'pipeline_planner',
        ]);

        return $this->parseTaskJson($raw, $maxTasks);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Requirement>  $requirements
     * @return list<array{title: string, description: string, requirement_code?: string, sort_order?: int, prompt_template?: string, files_hint?: list<string>}>
     */
    private function fallbackFromRequirements($requirements, int $maxTasks): array
    {
        if ($requirements->isEmpty()) {
            return [[
                'title' => 'Implement core SRS features',
                'description' => 'Implement the highest-priority features described in the SRS.',
                'sort_order' => 1,
                'prompt_template' => 'Implement the features described in the SRS document.',
                'files_hint' => [],
            ]];
        }

        return $requirements
            ->take($maxTasks)
            ->values()
            ->map(fn (Requirement $r, int $i) => [
                'title' => $r->title ?: "Implement {$r->code}",
                'description' => (string) $r->body,
                'requirement_code' => (string) $r->code,
                'sort_order' => $i + 1,
                'prompt_template' => "Implement requirement {$r->code}: {$r->title}\n\n{$r->body}\n\nAcceptance:\n{$r->gherkin}",
                'files_hint' => [],
            ])
            ->all();
    }

    /**
     * @return list<array{title: string, description: string, requirement_code?: string, sort_order?: int, prompt_template?: string, files_hint?: list<string>}>
     */
    private function parseTaskJson(string $raw, int $maxTasks): array
    {
        $trimmed = trim($raw);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Planner returned invalid JSON.');
        }

        $items = array_is_list($decoded) ? $decoded : ($decoded['tasks'] ?? []);
        if (! is_array($items)) {
            throw new \RuntimeException('Planner JSON missing tasks array.');
        }

        $normalized = [];
        foreach (array_slice($items, 0, $maxTasks) as $item) {
            if (! is_array($item) || blank($item['title'] ?? null)) {
                continue;
            }
            $normalized[] = [
                'title' => (string) $item['title'],
                'description' => (string) ($item['description'] ?? ''),
                'requirement_code' => isset($item['requirement_code']) ? (string) $item['requirement_code'] : null,
                'sort_order' => (int) ($item['sort_order'] ?? (count($normalized) + 1)),
                'prompt_template' => (string) ($item['prompt_template'] ?? $item['description'] ?? ''),
                'files_hint' => array_values(array_filter(
                    array_map('strval', is_array($item['files_hint'] ?? null) ? $item['files_hint'] : [])
                )),
            ];
        }

        return $normalized;
    }
}
