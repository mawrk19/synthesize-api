<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Http\Requests\StoreSrsDocumentRequest;
use App\Modules\Documents\Http\Requests\UpdateSrsDocumentRequest;
use App\Modules\Documents\Http\Resources\SrsDocumentResource;
use App\Modules\Documents\Services\SrsDocumentService;
use Dedoc\Scramble\Attributes\HeaderParameter;

class SrsDocumentController extends Controller
{
    public function __construct(
        private readonly SrsDocumentService $documentService,
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function index(\Illuminate\Http\Request $request)
    {
        return SrsDocumentResource::collection(
            $this->documentService->listForCurrentUser($request->query('project_id'))
        );
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function store(StoreSrsDocumentRequest $request)
    {
        $document = $this->documentService->create(
            title: $request->string('title')->toString(),
            notes: $request->input('notes'),
            file: $request->file('file'),
            projectId: $request->input('project_id'),
        );

        return (new SrsDocumentResource($document))
            ->response()
            ->setStatusCode(201);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function show(string $id)
    {
        $document = $this->documentService->findForCurrentUser($id);

        if (! $document) {
            abort(404, 'Document not found');
        }

        return new SrsDocumentResource($document);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function regenerate(string $id)
    {
        $document = $this->documentService->regenerate($id);

        if (! $document) {
            abort(404, 'Document not found');
        }

        return new SrsDocumentResource($document);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function update(UpdateSrsDocumentRequest $request, string $id)
    {
        $document = $this->documentService->updateTitle(
            id: $id,
            title: $request->string('title')->toString(),
        );

        if (! $document) {
            abort(404, 'Document not found');
        }

        return new SrsDocumentResource($document);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function destroy(string $id)
    {
        if (! $this->documentService->delete($id)) {
            abort(404, 'Document not found');
        }

        return response()->json(null, 204);
    }
}
