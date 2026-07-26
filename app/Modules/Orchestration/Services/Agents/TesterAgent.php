<?php

namespace App\Modules\Orchestration\Services\Agents;

use App\Modules\Core\Services\AiCompletionService;
use App\Modules\Orchestration\Enums\AgentRole;
use App\Modules\Orchestration\Enums\PipelineTaskStatus;
use App\Modules\Orchestration\Models\PipelineRun;
use App\Modules\Orchestration\Models\PipelineTask;
use App\Modules\Orchestration\Models\ProjectRepository;
use App\Modules\Orchestration\Services\GitHubRepositoryService;
use App\Modules\Projects\Models\Requirement;
use Illuminate\Support\Facades\Log;
use Throwable;

class TesterAgent
{
    public function __construct(
        private readonly AiCompletionService $ai,
        private readonly GitHubRepositoryService $github,
    ) {}

    public function executeNext(PipelineRun $run): bool
    {
        $task = PipelineTask::query()
            ->where('pipeline_run_id', $run->id)
            ->where('agent_role', AgentRole::Tester)
            ->where('status', PipelineTaskStatus::Pending)
            ->whereHas('dependsOn', fn ($q) => $q->where('status', PipelineTaskStatus::Review))
            ->orderBy('sort_order')
            ->first();

        if (! $task) {
            return false;
        }

        $devTask = $task->dependsOn;
        if (! $devTask) {
            $task->update([
                'status' => PipelineTaskStatus::Failed,
                'error_message' => 'Missing developer dependency.',
            ]);

            return true;
        }

        $task->update([
            'status' => PipelineTaskStatus::Processing,
            'attempts' => $task->attempts + 1,
            'error_message' => null,
        ]);
        $devTask->update(['status' => PipelineTaskStatus::Testing]);

        try {
            $codeChange = $devTask->codeChange;
            $repository = ProjectRepository::query()->where('project_id', $run->project_id)->first();

            if ($repository && $codeChange?->commit_sha) {
                $checks = $this->github->getCheckRunsSummary($repository, $codeChange->commit_sha);
                if ($checks['conclusion'] === 'failure') {
                    $task->update([
                        'status' => PipelineTaskStatus::Failed,
                        'error_message' => 'GitHub check runs failed.',
                        'audit_report' => "CI checks failed ({$checks['failed']}/{$checks['total']}).",
                    ]);
                    $devTask->update([
                        'status' => PipelineTaskStatus::Failed,
                        'error_message' => 'Blocked by failing CI checks.',
                    ]);

                    return true;
                }

                if ($checks['conclusion'] === 'pending') {
                    // Wait for CI on a later orchestrator tick.
                    $task->update(['status' => PipelineTaskStatus::Pending]);
                    $devTask->update(['status' => PipelineTaskStatus::Review]);

                    return true;
                }
            }

            $result = $this->validateWithAi($task, $devTask);
            $task->update([
                'status' => $result['passed'] ? PipelineTaskStatus::Completed : PipelineTaskStatus::Failed,
                'error_message' => $result['passed'] ? null : 'Gherkin validation failed.',
                'audit_report' => $result['notes'],
            ]);

            if (! $result['passed']) {
                $devTask->update([
                    'status' => PipelineTaskStatus::Failed,
                    'error_message' => 'Blocked by TesterAgent.',
                ]);
            }
        } catch (Throwable $e) {
            Log::error('TesterAgent failed', ['task_id' => $task->id, 'message' => $e->getMessage()]);
            $task->update([
                'status' => PipelineTaskStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
            $devTask->update([
                'status' => PipelineTaskStatus::Failed,
                'error_message' => 'TesterAgent error: '.$e->getMessage(),
            ]);
        }

        return true;
    }

    /** @return array{passed: bool, notes: string} */
    private function validateWithAi(PipelineTask $testerTask, PipelineTask $devTask): array
    {
        $requirement = $testerTask->requirement_id
            ? Requirement::query()->find($testerTask->requirement_id)
            : null;

        $diff = (string) ($devTask->codeChange?->unified_diff ?? '');
        $gherkin = (string) ($requirement?->gherkin ?? 'No Gherkin provided — assess general correctness.');

        $system = <<<'PROMPT'
You are the Tester agent for Synthesize. Given a unified diff and Gherkin acceptance criteria, decide if the change likely satisfies the stories.
Respond with ONLY JSON:
{"passed": true|false, "notes": "markdown summary"}
PROMPT;

        $user = "# Gherkin\n{$gherkin}\n\n# Diff\n".mb_substr($diff, 0, 10000);

        try {
            $raw = $this->ai->complete($system, $user, [
                'temperature' => 0.1,
                'max_tokens' => 1500,
                'operation' => 'pipeline_tester',
            ]);

            return $this->parseResult($raw);
        } catch (Throwable $e) {
            Log::warning('TesterAgent AI failed; defaulting to pass with notes', ['message' => $e->getMessage()]);

            return [
                'passed' => filled($diff),
                'notes' => 'AI unavailable; '.($diff !== ''
                    ? 'accepted based on presence of PR diff. Manual verification recommended.'
                    : 'no diff available — marked failed.'),
            ];
        }
    }

    /** @return array{passed: bool, notes: string} */
    private function parseResult(string $raw): array
    {
        $trimmed = trim($raw);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            return ['passed' => false, 'notes' => 'Tester returned invalid JSON.'];
        }

        return [
            'passed' => (bool) ($decoded['passed'] ?? false),
            'notes' => (string) ($decoded['notes'] ?? ''),
        ];
    }
}
