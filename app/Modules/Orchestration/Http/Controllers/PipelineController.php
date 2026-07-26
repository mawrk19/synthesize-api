<?php

namespace App\Modules\Orchestration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Services\SrsDocumentService;
use App\Modules\Orchestration\Http\Requests\ApprovePipelineRunRequest;
use App\Modules\Orchestration\Http\Requests\UpsertProjectRepositoryRequest;
use App\Modules\Orchestration\Http\Resources\PipelineRunResource;
use App\Modules\Orchestration\Http\Resources\PipelineTaskResource;
use App\Modules\Orchestration\Http\Resources\ProjectRepositoryResource;
use App\Modules\Orchestration\Models\ProjectRepository;
use App\Modules\Orchestration\Services\PipelineOrchestrator;
use App\Modules\Projects\Services\ProjectService;
use Dedoc\Scramble\Attributes\HeaderParameter;
use RuntimeException;
use Throwable;

class PipelineController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly SrsDocumentService $documentService,
        private readonly PipelineOrchestrator $orchestrator,
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function startFromDocument(string $id)
    {
        $document = $this->documentService->findForCurrentUser($id);
        if (! $document) {
            abort(404, 'Document not found');
        }

        $project = $this->projectService->findForCurrentUser($document->project_id);
        if (! $project) {
            abort(404, 'Project not found');
        }

        try {
            $run = $this->orchestrator->start($project, $document);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return (new PipelineRunResource($run->load('tasks')))
            ->response()
            ->setStatusCode(201);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function listRuns(string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);
        if (! $project) {
            abort(404, 'Project not found');
        }

        return PipelineRunResource::collection($this->orchestrator->listRuns($project));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function showRun(string $runId)
    {
        $run = \App\Modules\Orchestration\Models\PipelineRun::query()
            ->with(['tasks.codeChange', 'tasks.requirement', 'approvedBy'])
            ->find($runId);

        if (! $run) {
            abort(404, 'Pipeline run not found');
        }

        $project = $this->projectService->findForCurrentUser($run->project_id);
        if (! $project) {
            abort(404, 'Pipeline run not found');
        }

        return new PipelineRunResource($run);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function approve(ApprovePipelineRunRequest $request, string $projectId, string $runId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);
        if (! $project) {
            abort(404, 'Project not found');
        }

        $run = $this->orchestrator->findRunForProject($project, $runId);
        if (! $run) {
            abort(404, 'Pipeline run not found');
        }

        try {
            $run = $this->orchestrator->approve(
                $run,
                approverName: $request->input('approver_name'),
                taskIds: $request->input('task_ids'),
            );
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return new PipelineRunResource($run->load(['tasks.codeChange', 'tasks.requirement', 'approvedBy']));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function cancel(string $projectId, string $runId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);
        if (! $project) {
            abort(404, 'Project not found');
        }

        $run = $this->orchestrator->findRunForProject($project, $runId);
        if (! $run) {
            abort(404, 'Pipeline run not found');
        }

        try {
            $run = $this->orchestrator->cancel($run);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return new PipelineRunResource($run->load(['tasks.codeChange', 'tasks.requirement']));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function showTask(string $taskId)
    {
        $task = \App\Modules\Orchestration\Models\PipelineTask::query()
            ->with(['codeChange', 'requirement', 'run'])
            ->find($taskId);

        if (! $task) {
            abort(404, 'Pipeline task not found');
        }

        $project = $this->projectService->findForCurrentUser($task->project_id);
        if (! $project) {
            abort(404, 'Pipeline task not found');
        }

        return new PipelineTaskResource($task);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function showRepository(string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);
        if (! $project) {
            abort(404, 'Project not found');
        }

        $repository = ProjectRepository::query()->where('project_id', $project->id)->first();
        if (! $repository) {
            abort(404, 'Repository not configured');
        }

        return new ProjectRepositoryResource($repository);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function upsertRepository(UpsertProjectRepositoryRequest $request, string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);
        if (! $project) {
            abort(404, 'Project not found');
        }

        try {
            $repository = $this->orchestrator->upsertRepository(
                project: $project,
                owner: $request->string('owner')->toString(),
                repo: $request->string('repo')->toString(),
                defaultBranch: $request->input('default_branch', 'main'),
                basePath: $request->input('base_path'),
                token: $request->input('token'),
                mode: $request->input('mode', 'existing'),
                private: (bool) $request->boolean('private'),
                description: $request->input('description'),
            );
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }

        return new ProjectRepositoryResource($repository);
    }
}
