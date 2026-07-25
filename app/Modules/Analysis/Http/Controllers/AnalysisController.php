<?php

namespace App\Modules\Analysis\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analysis\Http\Resources\AnalysisRunResource;
use App\Modules\Analysis\Http\Resources\SchemaArtifactResource;
use App\Modules\Analysis\Services\AnalysisService;
use App\Modules\Documents\Services\SrsDocumentService;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalysisController extends Controller
{
    public function __construct(
        private readonly SrsDocumentService $documentService,
        private readonly AnalysisService $analysisService,
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function gap(string $documentId)
    {
        $document = $this->documentService->findForCurrentUser($documentId);
        if (! $document) {
            abort(404, 'Document not found');
        }

        return (new AnalysisRunResource($this->analysisService->startGap($document)))
            ->response()
            ->setStatusCode(201);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function validateRequirements(string $documentId)
    {
        $document = $this->documentService->findForCurrentUser($documentId);
        if (! $document) {
            abort(404, 'Document not found');
        }

        return (new AnalysisRunResource($this->analysisService->startValidator($document)))
            ->response()
            ->setStatusCode(201);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function schema(string $documentId)
    {
        $document = $this->documentService->findForCurrentUser($documentId);
        if (! $document) {
            abort(404, 'Document not found');
        }

        return (new SchemaArtifactResource($this->analysisService->startSchema($document)))
            ->response()
            ->setStatusCode(201);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function runs(string $documentId)
    {
        $document = $this->documentService->findForCurrentUser($documentId);
        if (! $document) {
            abort(404, 'Document not found');
        }

        return AnalysisRunResource::collection($this->analysisService->listRunsForDocument($documentId));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function schemas(string $documentId)
    {
        $document = $this->documentService->findForCurrentUser($documentId);
        if (! $document) {
            abort(404, 'Document not found');
        }

        return SchemaArtifactResource::collection($this->analysisService->listSchemasForDocument($documentId));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function showRun(string $runId)
    {
        $run = $this->analysisService->findRun($runId);
        if (! $run) {
            abort(404, 'Analysis run not found');
        }

        $document = $this->documentService->findForCurrentUser($run->srs_document_id);
        if (! $document) {
            abort(404, 'Analysis run not found');
        }

        return new AnalysisRunResource($run);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function showSchema(string $schemaId)
    {
        $schema = $this->analysisService->findSchema($schemaId);
        if (! $schema) {
            abort(404, 'Schema artifact not found');
        }

        $document = $this->documentService->findForCurrentUser($schema->srs_document_id);
        if (! $document) {
            abort(404, 'Schema artifact not found');
        }

        return new SchemaArtifactResource($schema);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function exportPrd(Request $request, string $documentId): StreamedResponse|JsonResponse
    {
        $document = $this->documentService->findForCurrentUser($documentId);
        if (! $document) {
            abort(404, 'Document not found');
        }

        $content = $this->analysisService->buildPrdExport($document);
        $filename = str_replace(' ', '-', strtolower($document->title)).'-prd.md';

        if ($request->boolean('preview')) {
            return response()->json([
                'data' => [
                    'kind' => 'prd',
                    'filename' => $filename,
                    'content' => $content,
                ],
            ]);
        }

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function exportReadme(Request $request, string $documentId): StreamedResponse|JsonResponse
    {
        $document = $this->documentService->findForCurrentUser($documentId);
        if (! $document) {
            abort(404, 'Document not found');
        }

        $content = $this->analysisService->buildReadmeExport($document);

        if ($request->boolean('preview')) {
            return response()->json([
                'data' => [
                    'kind' => 'readme',
                    'filename' => 'README.md',
                    'content' => $content,
                ],
            ]);
        }

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, 'README.md', ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }
}
