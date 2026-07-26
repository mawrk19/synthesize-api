<?php

namespace App\Modules\Orchestration\Services\Agents;

use App\Modules\Core\Services\AiCompletionService;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Orchestration\Enums\AgentRole;
use App\Modules\Orchestration\Enums\PipelineTaskStatus;
use App\Modules\Orchestration\Enums\PrStatus;
use App\Modules\Orchestration\Models\PipelineRun;
use App\Modules\Orchestration\Models\PipelineTask;
use App\Modules\Orchestration\Models\ProjectRepository;
use App\Modules\Orchestration\Models\TaskCodeChange;
use App\Modules\Orchestration\Services\GitHubRepositoryService;
use App\Modules\Projects\Models\Requirement;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DeveloperAgent
{
    public function __construct(
        private readonly AiCompletionService $ai,
        private readonly GitHubRepositoryService $github,
    ) {}

    public function executeNext(PipelineRun $run): bool
    {
        $task = PipelineTask::query()
            ->where('pipeline_run_id', $run->id)
            ->where('agent_role', AgentRole::Developer)
            ->where('status', PipelineTaskStatus::Pending)
            ->where(function ($q) {
                $q->whereNull('depends_on_task_id')
                    ->orWhereHas('dependsOn', fn ($d) => $d->where('status', PipelineTaskStatus::Completed));
            })
            ->orderBy('sort_order')
            ->first();

        if (! $task) {
            return false;
        }

        if ($task->codeChange) {
            $task->update(['status' => PipelineTaskStatus::Review]);

            return true;
        }

        $repository = ProjectRepository::query()->where('project_id', $run->project_id)->first();
        if (! $repository) {
            $task->update([
                'status' => PipelineTaskStatus::Failed,
                'error_message' => 'Project repository is not configured.',
            ]);

            return true;
        }

        $task->update([
            'status' => PipelineTaskStatus::Processing,
            'attempts' => $task->attempts + 1,
            'error_message' => null,
        ]);

        try {
            $this->implement($run, $task, $repository);
            $task->update(['status' => PipelineTaskStatus::Review]);
        } catch (Throwable $e) {
            Log::error('DeveloperAgent failed', [
                'task_id' => $task->id,
                'message' => $e->getMessage(),
            ]);
            $task->update([
                'status' => PipelineTaskStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
        }

        return true;
    }

    private function implement(PipelineRun $run, PipelineTask $task, ProjectRepository $repository): void
    {
        $document = SrsDocument::query()->find($run->srs_document_id);
        $requirement = $task->requirement_id
            ? Requirement::query()->find($task->requirement_id)
            : null;

        $tree = $this->github->getTree($repository);
        $paths = collect($tree['tree'])->pluck('path')->take(80)->all();
        $hints = is_array($task->files_hint) ? $task->files_hint : [];

        $snippets = [];
        foreach (array_slice(array_unique([...$hints, ...array_slice($paths, 0, 8)]), 0, 6) as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            try {
                $content = $this->github->getFileContent($repository, $path, $repository->default_branch);
                if ($content !== null) {
                    $snippets[] = "### {$path}\n```\n".mb_substr($content, 0, 2500)."\n```";
                }
            } catch (Throwable) {
                // skip unreadable files
            }
        }

        $files = $this->proposeFiles($task, $document, $requirement, $paths, $snippets);
        if ($files === []) {
            throw new RuntimeException('Developer agent produced no file changes.');
        }

        $shortId = Str::lower(Str::substr(str_replace('-', '', $task->id), 0, 8));
        $branch = "synthesize/task-{$shortId}";

        $this->github->createBranch($repository, $branch, $tree['sha']);

        $commitFiles = array_map(fn (array $f) => [
            'path' => $f['path'],
            'content' => $f['content'],
            'message' => "Synthesize: {$task->title}",
        ], $files);

        $commitSha = $this->github->commitFiles(
            $repository,
            $branch,
            $commitFiles,
            "Synthesize: {$task->title}",
        );

        $pr = $this->github->openPullRequest(
            $repository,
            $branch,
            "[Synthesize] {$task->title}",
            $this->prBody($task, $requirement),
        );

        $unifiedDiff = $this->github->compareDiff($repository, $repository->default_branch, $branch);
        if ($unifiedDiff === '') {
            $unifiedDiff = collect($files)
                ->map(fn (array $f) => "--- a/{$f['path']}\n+++ b/{$f['path']}\n@@\n{$f['content']}")
                ->implode("\n\n");
        }

        TaskCodeChange::query()->create([
            'pipeline_task_id' => $task->id,
            'branch_name' => $branch,
            'commit_sha' => $commitSha,
            'pr_number' => $pr['number'],
            'pr_url' => $pr['html_url'],
            'pr_status' => PrStatus::Open,
            'unified_diff' => $unifiedDiff,
            'files_changed' => array_map(fn (array $f) => [
                'path' => $f['path'],
                'action' => $f['action'] ?? 'update',
                'patch' => mb_substr($f['content'], 0, 5000),
            ], $files),
        ]);
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $snippets
     * @return list<array{path: string, content: string, action: string}>
     */
    private function proposeFiles(
        PipelineTask $task,
        ?SrsDocument $document,
        ?Requirement $requirement,
        array $paths,
        array $snippets,
    ): array {
        $srs = mb_substr((string) ($document?->generated_srs ?? ''), 0, 6000);
        $reqText = $requirement
            ? "[{$requirement->code}] {$requirement->title}\n{$requirement->body}\n\nGherkin:\n{$requirement->gherkin}"
            : '';
        $pathList = implode("\n", array_slice($paths, 0, 60));
        $snippetBlock = implode("\n\n", $snippets);

        $system = <<<'PROMPT'
You are the Developer agent for Synthesize. Produce concrete file changes for a GitHub repository.
Respond with ONLY a JSON object:
{
  "files": [
    {"path": "relative/path.ext", "action": "create"|"update", "content": "full file contents"}
  ]
}
Keep changes minimal and focused on the task. Prefer existing project conventions from snippets.
PROMPT;

        $user = <<<PROMPT
# Task
{$task->title}

{$task->description}

{$task->prompt_template}

# Requirement
{$reqText}

# SRS excerpt
{$srs}

# Existing paths
{$pathList}

# File snippets
{$snippetBlock}
PROMPT;

        try {
            $raw = $this->ai->complete($system, $user, [
                'temperature' => 0.15,
                'max_tokens' => 8192,
                'operation' => 'pipeline_developer',
            ]);

            return $this->parseFilesJson($raw);
        } catch (Throwable $e) {
            Log::warning('DeveloperAgent AI failed; using stub file', ['message' => $e->getMessage()]);

            $safeTitle = Str::slug($task->title) ?: 'task';
            $stubPath = "synthesize/{$safeTitle}.md";

            return [[
                'path' => $stubPath,
                'action' => 'create',
                'content' => "# {$task->title}\n\n{$task->description}\n\nGenerated by Synthesize DeveloperAgent fallback.\n",
            ]];
        }
    }

    /**
     * @return list<array{path: string, content: string, action: string}>
     */
    private function parseFilesJson(string $raw): array
    {
        $trimmed = trim($raw);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Developer agent returned invalid JSON.');
        }

        $files = $decoded['files'] ?? $decoded;
        if (! is_array($files)) {
            throw new RuntimeException('Developer agent JSON missing files.');
        }

        $normalized = [];
        foreach ($files as $file) {
            if (! is_array($file) || blank($file['path'] ?? null) || ! isset($file['content'])) {
                continue;
            }
            $normalized[] = [
                'path' => ltrim((string) $file['path'], '/'),
                'content' => (string) $file['content'],
                'action' => in_array($file['action'] ?? 'update', ['create', 'update'], true)
                    ? (string) $file['action']
                    : 'update',
            ];
        }

        return $normalized;
    }

    private function prBody(PipelineTask $task, ?Requirement $requirement): string
    {
        $lines = [
            '## Synthesize Developer Agent',
            '',
            "**Task:** {$task->title}",
            '',
            $task->description ?? '',
        ];

        if ($requirement) {
            $lines[] = '';
            $lines[] = "**Requirement:** {$requirement->code} — {$requirement->title}";
        }

        $lines[] = '';
        $lines[] = '_This PR was opened automatically by the Synthesize orchestration pipeline._';

        return implode("\n", $lines);
    }
}
