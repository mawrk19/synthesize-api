<?php

namespace App\Modules\Collaboration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Collaboration\Http\Requests\StoreCommentRequest;
use App\Modules\Collaboration\Http\Requests\StoreReviewLinkRequest;
use App\Modules\Collaboration\Http\Resources\CommentResource;
use App\Modules\Collaboration\Http\Resources\DocumentVersionResource;
use App\Modules\Collaboration\Http\Resources\ReviewLinkResource;
use App\Modules\Collaboration\Services\CollaborationService;
use App\Modules\Documents\Http\Resources\SrsDocumentResource;
use App\Modules\Documents\Services\SrsDocumentService;
use App\Modules\Projects\Models\Requirement;
use App\Modules\Projects\Services\ProjectService;
use Dedoc\Scramble\Attributes\HeaderParameter;

class CollaborationController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly CollaborationService $collaborationService,
        private readonly SrsDocumentService $documentService,
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function storeReviewLink(StoreReviewLinkRequest $request, string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);
        if (! $project) {
            abort(404, 'Project not found');
        }

        $link = $this->collaborationService->createReviewLink(
            project: $project,
            expiresAt: $request->input('expires_at'),
            allowComment: $request->boolean('allow_comment', true),
        );

        return (new ReviewLinkResource($link))->response()->setStatusCode(201);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function listReviewLinks(string $projectId)
    {
        $project = $this->projectService->findForCurrentUser($projectId);
        if (! $project) {
            abort(404, 'Project not found');
        }

        return ReviewLinkResource::collection($this->collaborationService->listReviewLinks($project));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function destroyReviewLink(string $projectId, string $id)
    {
        $project = $this->projectService->findForCurrentUser($projectId);
        if (! $project) {
            abort(404, 'Project not found');
        }

        if (! $this->collaborationService->deleteReviewLink($project, $id)) {
            abort(404, 'Review link not found');
        }

        return response()->json(null, 204);
    }

    public function showReview(string $token)
    {
        $link = $this->collaborationService->findReviewByToken($token);
        if (! $link) {
            abort(404, 'Review link not found or expired');
        }

        return response()->json(['data' => $this->collaborationService->reviewPayload($link)]);
    }

    public function storeGuestComment(StoreCommentRequest $request, string $token, string $requirementId)
    {
        $link = $this->collaborationService->findReviewByToken($token);
        if (! $link) {
            abort(404, 'Review link not found or expired');
        }

        if (! $link->allow_comment) {
            abort(403, 'Comments are disabled for this review link');
        }

        $requirement = Requirement::query()
            ->where('project_id', $link->project_id)
            ->where('id', $requirementId)
            ->first();

        if (! $requirement) {
            abort(404, 'Requirement not found');
        }

        $comment = $this->collaborationService->addComment(
            requirementId: $requirementId,
            body: $request->string('body')->toString(),
            userId: null,
            guestName: $request->input('guest_name', 'Guest'),
        );

        return (new CommentResource($comment))->response()->setStatusCode(201);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function listComments(string $requirementId)
    {
        $requirement = Requirement::query()->find($requirementId);
        if (! $requirement) {
            abort(404, 'Requirement not found');
        }

        $project = $this->projectService->findForCurrentUser($requirement->project_id);
        if (! $project) {
            abort(404, 'Requirement not found');
        }

        return CommentResource::collection($this->collaborationService->listComments($requirementId));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function storeComment(StoreCommentRequest $request, string $requirementId)
    {
        $requirement = Requirement::query()->find($requirementId);
        if (! $requirement) {
            abort(404, 'Requirement not found');
        }

        $project = $this->projectService->findForCurrentUser($requirement->project_id);
        if (! $project) {
            abort(404, 'Requirement not found');
        }

        $comment = $this->collaborationService->addComment(
            requirementId: $requirementId,
            body: $request->string('body')->toString(),
        );

        return (new CommentResource($comment))->response()->setStatusCode(201);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function resolveComment(string $commentId)
    {
        $comment = $this->collaborationService->resolveComment($commentId);
        if (! $comment) {
            abort(404, 'Comment not found');
        }

        $requirement = Requirement::query()->find($comment->requirement_id);
        $project = $requirement
            ? $this->projectService->findForCurrentUser($requirement->project_id)
            : null;

        if (! $project) {
            abort(404, 'Comment not found');
        }

        return new CommentResource($comment);
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function listVersions(string $documentId)
    {
        $document = $this->documentService->findForCurrentUser($documentId);
        if (! $document) {
            abort(404, 'Document not found');
        }

        return DocumentVersionResource::collection($this->collaborationService->listVersions($document));
    }

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function restoreVersion(string $documentId, string $versionId)
    {
        $document = $this->documentService->findForCurrentUser($documentId);
        if (! $document) {
            abort(404, 'Document not found');
        }

        $restored = $this->collaborationService->restoreVersion($document, $versionId);
        if (! $restored) {
            abort(404, 'Version not found');
        }

        return new SrsDocumentResource($restored);
    }
}
