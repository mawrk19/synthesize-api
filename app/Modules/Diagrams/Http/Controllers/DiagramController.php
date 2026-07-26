<?php

namespace App\Modules\Diagrams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Diagrams\Enums\DiagramType;
use App\Modules\Diagrams\Http\Requests\StoreDiagramRequest;
use App\Modules\Diagrams\Http\Requests\UpdateDiagramRequest;
use App\Modules\Diagrams\Http\Resources\DiagramResource;
use App\Modules\Diagrams\Services\DiagramService;
use App\Modules\Documents\Services\SrsDocumentService;
use App\Modules\Projects\Services\ProjectService;
use Dedoc\Scramble\Attributes\HeaderParameter;

class DiagramController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly DiagramService $diagramService,
        private readonly SrsDocumentService $documentService,
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function index(string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        return DiagramResource::collection($this->diagramService->listForProject($project));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function store(StoreDiagramRequest $request, string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $diagram = $this->diagramService->create(
            project: $project,
            type: DiagramType::from($request->string('type')->toString()),
            title: $request->string('title')->toString(),
            srsDocumentId: $request->input('srs_document_id'),
        );

        return (new DiagramResource($diagram))
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

        $diagram = $this->diagramService->findForProject($project, $id);

        if (! $diagram) {
            abort(404, 'Diagram not found');
        }

        return new DiagramResource($diagram);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function update(UpdateDiagramRequest $request, string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $diagram = $this->diagramService->updateSource(
            $project,
            $id,
            $request->string('mermaid_source')->toString(),
        );

        if (! $diagram) {
            abort(404, 'Diagram not found');
        }

        if ($request->filled('title')) {
            $diagram->update(['title' => $request->string('title')->toString()]);
            $diagram = $diagram->fresh();
        }

        return new DiagramResource($diagram);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function generate(string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        $diagram = $this->diagramService->regenerate($project, $id);

        if (! $diagram) {
            abort(404, 'Diagram not found');
        }

        return new DiagramResource($diagram);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function destroy(string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);

        if (! $project) {
            abort(404, 'Project not found');
        }

        if (! $this->diagramService->delete($project, $id)) {
            abort(404, 'Diagram not found');
        }

        return response()->json(null, 204);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function generateFromDocument(string $documentId)
    {
        $document = $this->documentService->findForCurrentUser($documentId);

        if (! $document) {
            abort(404, 'Document not found');
        }

        $diagrams = $this->diagramService->generateFromDocument($document);

        return DiagramResource::collection($diagrams);
    }
}
