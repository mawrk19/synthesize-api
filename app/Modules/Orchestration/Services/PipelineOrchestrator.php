<?php

namespace App\Modules\Orchestration\Services;

use App\Modules\Collaboration\Models\ReviewLink;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Orchestration\Enums\AgentRole;
use App\Modules\Orchestration\Enums\PipelineRunStatus;
use App\Modules\Orchestration\Enums\PipelineTaskStatus;
use App\Modules\Orchestration\Enums\ReviewApprovalStatus;
use App\Modules\Orchestration\Jobs\RunPipelineJob;
use App\Modules\Orchestration\Models\PipelineRun;
use App\Modules\Orchestration\Models\PipelineTask;
use App\Modules\Orchestration\Models\ProjectRepository;
use App\Modules\Orchestration\Services\Agents\DeveloperAgent;
use App\Modules\Orchestration\Services\Agents\PlannerAgent;
use App\Modules\Orchestration\Services\Agents\ReviewerAgent;
use App\Modules\Orchestration\Services\Agents\TesterAgent;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PipelineOrchestrator
{
    public function __construct(
        private readonly PlannerAgent $plannerAgent,
        private readonly DeveloperAgent $developerAgent,
        private readonly TesterAgent $testerAgent,
        private readonly ReviewerAgent $reviewerAgent,
        private readonly GitHubRepositoryService $github,
    ) {}

    public function start(Project $project, SrsDocument $document): PipelineRun
    {
        if ($document->project_id !== $project->id) {
            throw new RuntimeException('Document does not belong to project.');
        }

        if ($document->status !== DocumentStatus::Completed) {
            throw new RuntimeException('SRS document must be completed before starting a pipeline.');
        }

        $active = PipelineRun::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [
                PipelineRunStatus::Planning->value,
                PipelineRunStatus::AwaitingApproval->value,
                PipelineRunStatus::Executing->value,
            ])
            ->exists();

        if ($active) {
            throw new RuntimeException('An active pipeline run already exists for this project.');
        }

        $run = PipelineRun::query()->create([
            'project_id' => $project->id,
            'srs_document_id' => $document->id,
            'status' => PipelineRunStatus::Planning,
            'current_phase' => AgentRole::Planner,
        ]);

        RunPipelineJob::dispatch($run->id);

        return $run;
    }

    public function tick(string $runId): void
    {
        $maxInlineTicks = config('queue.default') === 'sync' ? 25 : 1;

        for ($i = 0; $i < $maxInlineTicks; $i++) {
            $shouldContinue = false;

            DB::transaction(function () use ($runId, &$shouldContinue): void {
                /** @var PipelineRun|null $run */
                $run = PipelineRun::query()->whereKey($runId)->lockForUpdate()->first();
                if (! $run) {
                    return;
                }

                match ($run->status) {
                    PipelineRunStatus::Planning => $this->plannerAgent->execute($run->fresh()),
                    PipelineRunStatus::AwaitingApproval => null,
                    PipelineRunStatus::Executing => $this->executePhase($run->fresh()),
                    default => null,
                };

                $run = $run->fresh();
                if ($run?->status === PipelineRunStatus::AwaitingApproval) {
                    $this->ensureApprovalReviewLink($run);
                }

                $shouldContinue = $run !== null && $this->shouldContinue($run);
            });

            if (! $shouldContinue) {
                return;
            }

            // Async workers: schedule the next tick and exit this job.
            if (config('queue.default') !== 'sync') {
                $delay = max(1, (int) config('services.pipeline.tick_delay_seconds', 5));
                RunPipelineJob::dispatch($runId)->delay(now()->addSeconds($delay));

                return;
            }
        }
    }

    public function ensureApprovalReviewLink(PipelineRun $run): ReviewLink
    {
        $existing = ReviewLink::query()
            ->where('pipeline_run_id', $run->id)
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->createApprovalReviewLink($run);
    }

    public function approve(
        PipelineRun $run,
        ?int $userId = null,
        ?string $approverName = null,
        ?array $taskIds = null,
    ): PipelineRun {
        if ($run->status !== PipelineRunStatus::AwaitingApproval) {
            throw new RuntimeException('Pipeline run is not awaiting approval.');
        }

        $this->applyTaskInclusion($run, $taskIds);

        $run->update([
            'status' => PipelineRunStatus::Executing,
            'current_phase' => AgentRole::Developer,
            'approved_at' => now(),
            'approved_by_user_id' => $userId ?? Auth::id(),
            'error_message' => null,
        ]);

        ReviewLink::query()
            ->where('pipeline_run_id', $run->id)
            ->where('approval_status', ReviewApprovalStatus::Pending)
            ->update([
                'approval_status' => ReviewApprovalStatus::Approved,
                'approved_at' => now(),
                'approved_by_name' => $approverName ?? (Auth::user()
                    ? trim(((string) Auth::user()->first_name).' '.((string) Auth::user()->last_name))
                    : 'Owner'),
            ]);

        RunPipelineJob::dispatch($run->id);

        return $run->fresh(['tasks.codeChange', 'tasks.requirement', 'approvedBy']);
    }

    /**
     * Include selected developer tasks; skip the rest and their dependent tester/reviewer tasks.
     *
     * @param  list<string>|null  $taskIds  Developer task IDs to include. Null = include all.
     */
    private function applyTaskInclusion(PipelineRun $run, ?array $taskIds): void
    {
        $developerTasks = PipelineTask::query()
            ->where('pipeline_run_id', $run->id)
            ->where('agent_role', AgentRole::Developer)
            ->get();

        if ($developerTasks->isEmpty()) {
            return;
        }

        if ($taskIds === null) {
            PipelineTask::query()
                ->where('pipeline_run_id', $run->id)
                ->update(['included_in_plan' => true]);

            return;
        }

        $taskIds = array_values(array_unique(array_filter($taskIds, fn ($id) => is_string($id) && $id !== '')));
        $validIds = $developerTasks->pluck('id')->all();
        $invalid = array_diff($taskIds, $validIds);
        if ($invalid !== []) {
            throw new RuntimeException('One or more task_ids are invalid for this pipeline run.');
        }

        if ($taskIds === []) {
            throw new RuntimeException('At least one developer task must be approved.');
        }

        $includeSet = array_flip($taskIds);

        foreach ($developerTasks as $devTask) {
            $include = isset($includeSet[$devTask->id]);
            if ($include) {
                $devTask->update(['included_in_plan' => true]);

                continue;
            }

            $devTask->update([
                'included_in_plan' => false,
                'status' => PipelineTaskStatus::Skipped,
                'error_message' => 'Skipped at approval — not selected for development.',
            ]);

            PipelineTask::query()
                ->where('pipeline_run_id', $run->id)
                ->where(function ($q) use ($devTask) {
                    $q->where('parent_task_id', $devTask->id)
                        ->orWhere('depends_on_task_id', $devTask->id);
                })
                ->whereIn('agent_role', [AgentRole::Tester->value, AgentRole::Reviewer->value])
                ->update([
                    'included_in_plan' => false,
                    'status' => PipelineTaskStatus::Skipped,
                    'error_message' => 'Skipped because parent developer task was not approved.',
                ]);

            // Reviewer depends on tester — catch any remaining children of skipped tester.
            $testerIds = PipelineTask::query()
                ->where('pipeline_run_id', $run->id)
                ->where('parent_task_id', $devTask->id)
                ->where('agent_role', AgentRole::Tester)
                ->pluck('id');

            if ($testerIds->isNotEmpty()) {
                PipelineTask::query()
                    ->where('pipeline_run_id', $run->id)
                    ->whereIn('depends_on_task_id', $testerIds)
                    ->where('agent_role', AgentRole::Reviewer)
                    ->where('status', '!=', PipelineTaskStatus::Skipped->value)
                    ->update([
                        'included_in_plan' => false,
                        'status' => PipelineTaskStatus::Skipped,
                        'error_message' => 'Skipped because parent developer task was not approved.',
                    ]);
            }
        }
    }

    public function cancel(PipelineRun $run): PipelineRun
    {
        if ($run->status->isTerminal()) {
            throw new RuntimeException('Pipeline run is already terminal.');
        }

        $run->update([
            'status' => PipelineRunStatus::Cancelled,
            'error_message' => 'Cancelled by user.',
        ]);

        PipelineTask::query()
            ->where('pipeline_run_id', $run->id)
            ->whereNotIn('status', [
                PipelineTaskStatus::Completed->value,
                PipelineTaskStatus::Failed->value,
                PipelineTaskStatus::Skipped->value,
            ])
            ->update(['status' => PipelineTaskStatus::Blocked]);

        return $run->fresh();
    }

    public function createApprovalReviewLink(PipelineRun $run, bool $allowComment = true): ReviewLink
    {
        return ReviewLink::query()->create([
            'project_id' => $run->project_id,
            'pipeline_run_id' => $run->id,
            'token' => Str::random(48),
            'allow_comment' => $allowComment,
            'approval_status' => ReviewApprovalStatus::Pending,
        ]);
    }

    public function upsertRepository(
        Project $project,
        string $owner,
        string $repo,
        string $defaultBranch = 'main',
        ?string $basePath = null,
        ?string $token = null,
        string $mode = 'existing',
        bool $private = false,
        ?string $description = null,
    ): ProjectRepository {
        if ($mode === 'new') {
            $resolvedToken = $this->github->resolveTokenOrFail($token);
            $created = $this->github->createRepository(
                token: $resolvedToken,
                owner: $owner,
                name: $repo,
                private: $private,
                description: $description,
            );

            $owner = $created['owner'];
            $repo = $created['repo'];
            $defaultBranch = $created['default_branch'] ?: $defaultBranch;
        }

        $repository = ProjectRepository::query()->firstOrNew(['project_id' => $project->id]);
        $repository->fill([
            'provider' => 'github',
            'owner' => $owner,
            'repo' => $repo,
            'default_branch' => $defaultBranch,
            'base_path' => $basePath,
        ]);

        if ($token !== null && $token !== '') {
            $repository->setToken($token);
        }

        $repository->save();

        $repository = $repository->fresh();

        try {
            return $this->github->ensureRepositoryInitialized($repository);
        } catch (RuntimeException $e) {
            $repository->setAttribute('initialization_warning', $e->getMessage());

            return $repository;
        }
    }

    /** @return Collection<int, PipelineRun> */
    public function listRuns(Project $project): Collection
    {
        return PipelineRun::query()
            ->where('project_id', $project->id)
            ->withCount('tasks')
            ->latest()
            ->get();
    }

    public function findRunForProject(Project $project, string $runId): ?PipelineRun
    {
        return PipelineRun::query()
            ->where('project_id', $project->id)
            ->where('id', $runId)
            ->with(['tasks.codeChange', 'tasks.requirement', 'approvedBy'])
            ->first();
    }

    public function findTaskForProject(Project $project, string $taskId): ?PipelineTask
    {
        return PipelineTask::query()
            ->where('project_id', $project->id)
            ->where('id', $taskId)
            ->with(['codeChange', 'requirement', 'run'])
            ->first();
    }

    private function executePhase(PipelineRun $run): void
    {
        $handled = $this->developerAgent->executeNext($run);
        if ($handled) {
            $run->update(['current_phase' => AgentRole::Developer]);

            return;
        }

        $handled = $this->testerAgent->executeNext($run);
        if ($handled) {
            $run->update(['current_phase' => AgentRole::Tester]);

            return;
        }

        $handled = $this->reviewerAgent->executeNext($run);
        if ($handled) {
            $run->update(['current_phase' => AgentRole::Reviewer]);

            return;
        }

        $this->blockOrphanedTasks($run);
        $this->finalizeIfDone($run);
    }

    private function blockOrphanedTasks(PipelineRun $run): void
    {
        $pending = PipelineTask::query()
            ->where('pipeline_run_id', $run->id)
            ->where('status', PipelineTaskStatus::Pending)
            ->whereNotNull('depends_on_task_id')
            ->with('dependsOn')
            ->get();

        foreach ($pending as $task) {
            $dep = $task->dependsOn;
            if ($dep && in_array($dep->status, [PipelineTaskStatus::Failed, PipelineTaskStatus::Blocked, PipelineTaskStatus::Skipped], true)) {
                $task->update([
                    'status' => PipelineTaskStatus::Blocked,
                    'error_message' => $dep->status === PipelineTaskStatus::Skipped
                        ? 'Blocked because dependency was skipped.'
                        : 'Blocked because dependency failed.',
                ]);
            }
        }
    }

    private function finalizeIfDone(PipelineRun $run): void
    {
        $tasks = PipelineTask::query()->where('pipeline_run_id', $run->id)->get();
        if ($tasks->isEmpty()) {
            $run->update([
                'status' => PipelineRunStatus::Failed,
                'error_message' => 'No tasks to execute.',
            ]);

            return;
        }

        $allTerminal = $tasks->every(fn (PipelineTask $t) => $t->status->isTerminal());
        if (! $allTerminal) {
            return;
        }

        $anyFailed = $tasks->contains(fn (PipelineTask $t) => $t->status === PipelineTaskStatus::Failed);

        $run->update([
            'status' => $anyFailed ? PipelineRunStatus::Failed : PipelineRunStatus::Completed,
            'error_message' => $anyFailed ? 'One or more pipeline tasks failed.' : null,
            'current_phase' => AgentRole::Reviewer,
        ]);
    }

    private function shouldContinue(PipelineRun $run): bool
    {
        return $run->status->isActive();
    }
}
