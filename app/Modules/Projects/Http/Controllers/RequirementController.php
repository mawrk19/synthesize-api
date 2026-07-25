<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Http\Resources\RequirementResource;
use App\Modules\Projects\Models\Requirement;
use App\Modules\Projects\Services\ProjectService;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\Request;

class RequirementController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService,
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function index(Request $request, string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $query = Requirement::query()
            ->where('project_id', $project->id)
            ->withCount('comments')
            ->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        return RequirementResource::collection($query->get());
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function show(string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $requirement = Requirement::query()
            ->where('project_id', $project->id)
            ->where('id', $id)
            ->withCount('comments')
            ->first();

        if (! $requirement) {
            abort(404, 'Requirement not found');
        }

        return new RequirementResource($requirement);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function clearValidationFlags(string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $requirement = Requirement::query()
            ->where('project_id', $project->id)
            ->where('id', $id)
            ->first();

        if (! $requirement) {
            abort(404, 'Requirement not found');
        }

        $requirement->update(['validation_flags' => null]);

        return new RequirementResource($requirement->fresh()->loadCount('comments'));
    }
}
