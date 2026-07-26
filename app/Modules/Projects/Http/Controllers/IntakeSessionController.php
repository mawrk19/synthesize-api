<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Http\Resources\SrsDocumentResource;
use App\Modules\Projects\Http\Requests\GenerateSrsFromIntakeRequest;
use App\Modules\Projects\Http\Requests\StoreIntakeSessionRequest;
use App\Modules\Projects\Http\Requests\StoreTranscriptRequest;
use App\Modules\Projects\Http\Requests\UpdateIntakeSessionRequest;
use App\Modules\Projects\Http\Resources\IntakeSessionResource;
use App\Modules\Projects\Services\IntakeSessionService;
use App\Modules\Projects\Services\ProjectService;
use Dedoc\Scramble\Attributes\HeaderParameter;

class IntakeSessionController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly IntakeSessionService $intakeSessionService,
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function index(string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        return IntakeSessionResource::collection($this->intakeSessionService->listForProject($project));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function store(StoreIntakeSessionRequest $request, string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $session = $this->intakeSessionService->createBrainDump(
            project: $project,
            title: $request->input('title'),
            rawContent: $request->input('raw_content'),
        );

        return (new IntakeSessionResource($session))
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

        $session = $this->intakeSessionService->findForProject($project, $id);

        if (! $session) {
            abort(404, 'Intake session not found');
        }

        return new IntakeSessionResource($session);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function update(UpdateIntakeSessionRequest $request, string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $session = $this->intakeSessionService->update($project, $id, $request->validated());

        if (! $session) {
            abort(404, 'Intake session not found');
        }

        return new IntakeSessionResource($session);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function destroy(string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        if (! $this->intakeSessionService->delete($project, $id)) {
            abort(404, 'Intake session not found');
        }

        return response()->json(null, 204);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function structure(string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $session = $this->intakeSessionService->structure($project, $id);

        if (! $session) {
            abort(404, 'Intake session not found');
        }

        return new IntakeSessionResource($session);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function generateSrs(GenerateSrsFromIntakeRequest $request, string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $document = $this->intakeSessionService->generateSrs(
            project: $project,
            sessionId: $id,
            title: $request->input('title'),
        );

        if (! $document) {
            abort(404, 'Intake session not found');
        }

        return (new SrsDocumentResource($document))
            ->response()
            ->setStatusCode(201);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function storeTranscript(StoreTranscriptRequest $request, string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        if ($request->hasFile('audio')) {
            $session = $this->intakeSessionService->createFromAudio(
                project: $project,
                file: $request->file('audio'),
                title: $request->input('title'),
            );
        } else {
            $session = $this->intakeSessionService->createFromTranscript(
                project: $project,
                transcript: (string) $request->input('transcript'),
                title: $request->input('title'),
            );
        }

        return (new IntakeSessionResource($session))
            ->response()
            ->setStatusCode(201);
    }
}
