<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Http\Requests\StoreContextFileRequest;
use App\Modules\Projects\Http\Resources\ContextFileResource;
use App\Modules\Projects\Services\ContextFileService;
use App\Modules\Projects\Services\ProjectService;
use Dedoc\Scramble\Attributes\HeaderParameter;

class ContextFileController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly ContextFileService $contextFileService,
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function index(string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        return ContextFileResource::collection($this->contextFileService->listForProject($project));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function store(StoreContextFileRequest $request, string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $file = $this->contextFileService->upload($project, $request->file('file'));

        return (new ContextFileResource($file))
            ->response()
            ->setStatusCode(201);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function show(string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $file = $this->contextFileService->findForProject($project, $id);

        if (! $file) {
            abort(404, 'Context file not found');
        }

        return new ContextFileResource($file);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function destroy(string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        if (! $this->contextFileService->delete($project, $id)) {
            abort(404, 'Context file not found');
        }

        return response()->json(null, 204);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function reextract(string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $file = $this->contextFileService->reextract($project, $id);

        if (! $file) {
            abort(404, 'Context file not found');
        }

        return new ContextFileResource($file);
    }
}
