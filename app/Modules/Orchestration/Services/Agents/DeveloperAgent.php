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
            ->where('included_in_plan', true)
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
        $paths = collect($tree['tree'] ?? [])
            ->filter(fn ($node) => ($node['type'] ?? '') === 'blob')
            ->pluck('path')
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values()
            ->all();

        $hints = is_array($task->files_hint) ? $task->files_hint : [];
        $exemplars = $this->selectExemplarPaths($paths, $hints, $task);

        $snippets = [];
        $snippetBodies = [];
        foreach ($exemplars as $path) {
            try {
                $content = $this->github->getFileContent($repository, $path, $repository->default_branch);
                if ($content !== null) {
                    $snippetBodies[$path] = $content;
                    $snippets[] = "### {$path}\n```\n".mb_substr($content, 0, 3500)."\n```";
                }
            } catch (Throwable) {
                // skip unreadable files
            }
        }

        // Also pull build manifests for stack signals when present
        foreach (['pom.xml', 'build.gradle', 'build.gradle.kts', 'package.json', 'composer.json'] as $manifest) {
            $match = collect($paths)->first(
                fn ($p) => is_string($p) && (basename($p) === $manifest || str_ends_with($p, '/'.$manifest))
            );
            if (is_string($match) && ! isset($snippetBodies[$match])) {
                try {
                    $content = $this->github->getFileContent($repository, $match, $repository->default_branch);
                    if ($content !== null) {
                        $snippetBodies[$match] = $content;
                        array_unshift($snippets, "### {$match}\n```\n".mb_substr($content, 0, 2000)."\n```");
                    }
                } catch (Throwable) {
                    // ignore
                }
            }
        }

        $stackNotes = $this->inferStackNotes($paths, $snippetBodies);

        $files = $this->proposeFiles($task, $document, $requirement, $paths, $snippets, $stackNotes);
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
     * Prefer real peer source files over README / lockfiles so the model copies project style.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $hints
     * @return list<string>
     */
    private function selectExemplarPaths(array $paths, array $hints, PipelineTask $task): array
    {
        $hintList = array_values(array_filter($hints, fn ($h) => is_string($h) && $h !== ''));
        $scored = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $score = 0;
            $base = basename($path);
            $lower = Str::lower($path);

            foreach ($hintList as $hint) {
                if (Str::contains($lower, Str::lower($hint)) || Str::contains(Str::lower($hint), $lower)) {
                    $score += 100;
                }
            }

            // Skip noise
            if (preg_match('/(readme|license|changelog|package-lock|yarn\.lock|\.min\.|vendor\/|node_modules\/)/i', $path)) {
                $score -= 50;
            }

            // Prefer source over docs/config
            if (preg_match('/\.(java|kt|ts|tsx|js|jsx|php|py|go|cs|rb)$/i', $path)) {
                $score += 20;
            }
            if (preg_match('/\.(md|txt|json|ya?ml|xml|gradle|properties)$/i', $path)) {
                $score -= 5;
            }

            // Peer patterns for forms / DTOs / controllers / services
            if (preg_match('/(form|dto|request|response|controller|service|entity|model|repository|mapper|validator)/i', $base)) {
                $score += 35;
            }

            $taskBlob = Str::lower(implode(' ', array_filter([
                $task->title,
                $task->description,
                is_array($task->files_hint) ? implode(' ', $task->files_hint) : '',
            ])));

            foreach (preg_split('/[^a-z0-9]+/', $taskBlob) ?: [] as $token) {
                if (strlen($token) >= 4 && str_contains($lower, $token)) {
                    $score += 8;
                }
            }

            // Prefer files under same package/folder as hints
            foreach ($hintList as $hint) {
                $hintDir = Str::lower(trim(dirname($hint), '.'));
                if ($hintDir !== '' && $hintDir !== '/' && str_starts_with($lower, $hintDir.'/')) {
                    $score += 25;
                }
            }

            if ($score > 0) {
                $scored[$path] = $score;
            }
        }

        arsort($scored);
        $selected = array_slice(array_keys($scored), 0, 10);

        // Always include explicit hints first when they exist in the tree
        $pathSet = array_flip($paths);
        $ordered = [];
        foreach ($hintList as $hint) {
            $normalized = ltrim($hint, '/');
            if (isset($pathSet[$normalized])) {
                $ordered[] = $normalized;
            }
        }

        foreach ($selected as $path) {
            if (! in_array($path, $ordered, true)) {
                $ordered[] = $path;
            }
        }

        // Fallback: first source-looking files
        if ($ordered === []) {
            $ordered = collect($paths)
                ->filter(fn ($p) => preg_match('/\.(java|kt|ts|tsx|js|jsx|php|py|go|cs)$/i', (string) $p))
                ->take(8)
                ->values()
                ->all();
        }

        return array_slice(array_values(array_unique($ordered)), 0, 10);
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, string>  $snippetBodies
     */
    private function inferStackNotes(array $paths, array $snippetBodies = []): string
    {
        $joined = Str::lower(implode("\n", array_slice($paths, 0, 400)));
        $code = Str::lower(implode("\n", array_map(fn ($c) => mb_substr($c, 0, 1500), $snippetBodies)));
        $notes = [];

        if (str_contains($joined, 'pom.xml') || str_contains($joined, 'build.gradle') || str_contains($joined, '.java')) {
            $notes[] = 'This looks like a Java/JVM project.';
            if (str_contains($code, 'jakarta.') || str_contains($joined, 'jakarta')) {
                $notes[] = 'Use jakarta.* validation/servlet packages — do not import javax.* for new code.';
            } elseif (str_contains($code, 'javax.validation') || str_contains($code, 'javax.servlet')) {
                $notes[] = 'Peer files still import javax.* — match that legacy import style, do not upgrade unilaterally.';
            } else {
                $notes[] = 'If Spring Boot 3 is likely, prefer jakarta.validation over javax.validation.';
            }
            if (
                str_contains($code, 'lombok')
                || str_contains($code, '@data')
                || str_contains($code, '@getter')
                || str_contains($code, '@value')
                || str_contains($code, '@builder')
            ) {
                $notes[] = 'Exemplars use Lombok — prefer @Data/@Getter/@Builder etc. over hand-written getters/setters.';
            } else {
                $notes[] = 'Only write explicit getters/setters if exemplar classes do the same.';
            }
            if (str_contains($code, 'springframework') || str_contains($joined, '/controller/')) {
                $notes[] = 'Follow existing Spring patterns (controllers, @Valid, package layout from exemplars).';
            }
        }

        if (str_contains($joined, 'package.json') || preg_match('/\.(tsx?|jsx?)$/m', $joined)) {
            $notes[] = 'This looks like a JS/TS project — match existing module style, imports, and formatting.';
        }

        if (str_contains($joined, 'composer.json') || str_contains($joined, '.php')) {
            $notes[] = 'This looks like a PHP project — match existing namespaces, Form Requests, and style.';
        }

        if ($notes === []) {
            return 'Infer language and conventions strictly from the exemplar snippets below.';
        }

        return implode(' ', $notes);
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
        string $stackNotes = '',
    ): array {
        $srs = mb_substr((string) ($document?->generated_srs ?? ''), 0, 6000);
        $reqText = $requirement
            ? "[{$requirement->code}] {$requirement->title}\n{$requirement->body}\n\nGherkin:\n{$requirement->gherkin}"
            : '';
        $pathList = implode("\n", array_slice($paths, 0, 80));
        $snippetBlock = $snippets !== []
            ? implode("\n\n", $snippets)
            : '(No exemplar snippets available — still match paths and avoid inventing a new style.)';

        $system = <<<'PROMPT'
You are the Developer agent for Synthesize. Produce concrete file changes for an existing GitHub repository.

Respond with ONLY a JSON object:
{
  "files": [
    {"path": "relative/path.ext", "action": "create"|"update", "content": "full file contents"}
  ]
}

Coding standards (mandatory):
1. Mirror the exemplar snippets exactly: package/namespace layout, imports, naming, annotations, formatting, and layering.
2. Do NOT invent a new style. If peers use records/Lombok/@Data, use that. If peers use jakarta.*, never use javax.*. If peers omit verbose getters, do not add them.
3. Prefer editing/extending existing files over creating parallel one-off classes when a clear peer exists.
4. New files must live in the same package/folder conventions as similar types (Form next to Form, DTO next to DTO, Controller next to Controller).
5. Keep changes minimal and task-focused. No drive-by refactors, no unused imports, no placeholder comments like "// Getters and setters".
6. Match the project's validation / framework idioms from snippets (e.g. Spring @Valid + jakarta.validation, not random alternatives).
7. If the task is unclear, implement the smallest change that satisfies the requirement using existing patterns.
PROMPT;

        $user = <<<PROMPT
# Task
{$task->title}

{$task->description}

{$task->prompt_template}

# Requirement
{$reqText}

# Stack hints
{$stackNotes}

# SRS excerpt
{$srs}

# Existing paths (sample)
{$pathList}

# Exemplar file snippets (COPY THESE CONVENTIONS)
{$snippetBlock}
PROMPT;

        try {
            $raw = $this->ai->complete($system, $user, [
                'temperature' => 0.1,
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
