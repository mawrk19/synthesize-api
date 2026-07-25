<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Http\Requests\StoreProjectRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectRequest;
use App\Modules\Projects\Http\Resources\ProjectResource;
use App\Modules\Projects\Services\ProjectService;
use Dedoc\Scramble\Attributes\HeaderParameter;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService,
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function index()
    {
        return ProjectResource::collection($this->projectService->listForCurrentUser());
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function store(StoreProjectRequest $request)
    {
        $project = $this->projectService->create(
            name: $request->string('name')->toString(),
            description: $request->input('description'),
        );

        return (new ProjectResource($project->loadCount(['documents', 'requirements', 'contextFiles', 'intakeSessions'])))
            ->response()
            ->setStatusCode(201);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function show(string $id)
    {
        $project = $this->projectService->findForCurrentUser($id);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $project->loadCount(['documents', 'requirements', 'contextFiles', 'intakeSessions']);

        return new ProjectResource($project);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function update(UpdateProjectRequest $request, string $id)
    {
        $project = $this->projectService->update($id, $request->validated());

        if (! $project) {
            abort(404, 'Project not found');
        }

        return new ProjectResource($project->loadCount(['documents', 'requirements', 'contextFiles', 'intakeSessions']));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function destroy(string $id)
    {
        if (! $this->projectService->delete($id)) {
            abort(404, 'Project not found');
        }

        return response()->json(null, 204);
    }
}
