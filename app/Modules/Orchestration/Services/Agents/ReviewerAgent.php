<?php

namespace App\Modules\Orchestration\Services\Agents;

use App\Modules\Core\Services\AiCompletionService;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Orchestration\Enums\AgentRole;
use App\Modules\Orchestration\Enums\PipelineTaskStatus;
use App\Modules\Orchestration\Models\PipelineRun;
use App\Modules\Orchestration\Models\PipelineTask;
use App\Modules\Projects\Models\Requirement;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReviewerAgent
{
    public function __construct(
        private readonly AiCompletionService $ai,
    ) {}

    public function executeNext(PipelineRun $run): bool
    {
        $task = PipelineTask::query()
            ->where('pipeline_run_id', $run->id)
            ->where('agent_role', AgentRole::Reviewer)
            ->where('status', PipelineTaskStatus::Pending)
            ->whereHas('dependsOn', fn ($q) => $q->where('status', PipelineTaskStatus::Completed))
            ->orderBy('sort_order')
            ->first();

        if (! $task) {
            return false;
        }

        $testerTask = $task->dependsOn;
        $devTask = $testerTask?->dependsOn ?? $testerTask?->parentTask;

        $task->update([
            'status' => PipelineTaskStatus::Processing,
            'attempts' => $task->attempts + 1,
            'error_message' => null,
        ]);

        try {
            $report = $this->audit($run, $task, $devTask);
            $task->update([
                'status' => PipelineTaskStatus::Completed,
                'audit_report' => $report,
            ]);

            if ($devTask && $devTask->status !== PipelineTaskStatus::Completed) {
                $devTask->update(['status' => PipelineTaskStatus::Completed]);
            }
        } catch (Throwable $e) {
            Log::error('ReviewerAgent failed', ['task_id' => $task->id, 'message' => $e->getMessage()]);
            $task->update([
                'status' => PipelineTaskStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
        }

        return true;
    }

    private function audit(PipelineRun $run, PipelineTask $reviewerTask, ?PipelineTask $devTask): string
    {
        $document = SrsDocument::query()->find($run->srs_document_id);
        $requirement = $reviewerTask->requirement_id
            ? Requirement::query()->find($reviewerTask->requirement_id)
            : null;
        $diff = (string) ($devTask?->codeChange?->unified_diff ?? '');
        $srs = mb_substr((string) ($document?->generated_srs ?? ''), 0, 5000);
        $req = $requirement
            ? "[{$requirement->code}] {$requirement->title}\n{$requirement->body}"
            : ($devTask?->description ?? '');

        $system = <<<'PROMPT'
You are the Reviewer agent for Synthesize. Produce a concise markdown audit report covering:
1. Requirement coverage
2. Coding standards fit (does the diff match existing project conventions: packages, jakarta vs javax, Lombok vs hand-written accessors, naming, layering?)
3. Security / auth risks
4. Missing tests or edge cases
5. Overall recommendation (Approve / Request changes)

Flag generic boilerplate that ignores repo style as "Request changes".
PROMPT;

        $user = "# Requirement\n{$req}\n\n# SRS excerpt\n{$srs}\n\n# Diff\n".mb_substr($diff, 0, 10000);

        try {
            return $this->ai->complete($system, $user, [
                'temperature' => 0.2,
                'max_tokens' => 2000,
                'operation' => 'pipeline_reviewer',
            ]);
        } catch (Throwable $e) {
            Log::warning('ReviewerAgent AI failed; using stub report', ['message' => $e->getMessage()]);

            return "## AI Audit (fallback)\n\nAutomated review unavailable ({$e->getMessage()}).\n\n**Recommendation:** Manual review required.\n\nPR: ".($devTask?->codeChange?->pr_url ?? 'n/a');
        }
    }
}
