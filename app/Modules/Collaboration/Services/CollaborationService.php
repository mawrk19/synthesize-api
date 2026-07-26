<?php

namespace App\Modules\Collaboration\Services;

use App\Modules\Collaboration\Models\Comment;
use App\Modules\Collaboration\Models\DocumentVersion;
use App\Modules\Collaboration\Models\ReviewLink;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Orchestration\Enums\PipelineRunStatus;
use App\Modules\Orchestration\Enums\ReviewApprovalStatus;
use App\Modules\Orchestration\Models\PipelineRun;
use App\Modules\Orchestration\Services\PipelineOrchestrator;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Requirement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

class CollaborationService
{
    public function createReviewLink(
        Project $project,
        ?string $expiresAt = null,
        bool $allowComment = true,
        ?string $pipelineRunId = null,
    ): ReviewLink {
        return ReviewLink::query()->create([
            'project_id' => $project->id,
            'pipeline_run_id' => $pipelineRunId,
            'token' => Str::random(48),
            'expires_at' => $expiresAt,
            'allow_comment' => $allowComment,
            'approval_status' => ReviewApprovalStatus::Pending,
        ]);
    }

    /** @return Collection<int, ReviewLink> */
    public function listReviewLinks(Project $project): Collection
    {
        return ReviewLink::query()->where('project_id', $project->id)->latest()->get();
    }

    public function deleteReviewLink(Project $project, string $id): bool
    {
        $link = ReviewLink::query()
            ->where('project_id', $project->id)
            ->where('id', $id)
            ->first();

        if (! $link) {
            return false;
        }

        return (bool) $link->delete();
    }

    public function findReviewByToken(string $token): ?ReviewLink
    {
        $link = ReviewLink::query()->where('token', $token)->first();

        if (! $link || $link->isExpired()) {
            return null;
        }

        return $link;
    }

    /** @return Collection<int, Comment> */
    public function listComments(string $requirementId): Collection
    {
        return Comment::query()
            ->where('requirement_id', $requirementId)
            ->with('user')
            ->latest()
            ->get();
    }

    public function addComment(string $requirementId, string $body, ?int $userId = null, ?string $guestName = null): Comment
    {
        return Comment::query()->create([
            'requirement_id' => $requirementId,
            'user_id' => $userId ?? Auth::id(),
            'guest_name' => $guestName,
            'body' => $body,
        ]);
    }

    public function resolveComment(string $commentId): ?Comment
    {
        $comment = Comment::query()->find($commentId);

        if (! $comment) {
            return null;
        }

        $comment->update(['resolved_at' => now()]);

        return $comment->fresh();
    }

    /** @return Collection<int, DocumentVersion> */
    public function listVersions(SrsDocument $document): Collection
    {
        return $document->versions()->orderByDesc('version_number')->get();
    }

    public function restoreVersion(SrsDocument $document, string $versionId): ?SrsDocument
    {
        $version = DocumentVersion::query()
            ->where('srs_document_id', $document->id)
            ->where('id', $versionId)
            ->first();

        if (! $version) {
            return null;
        }

        if (filled($document->generated_srs)) {
            $next = (int) $document->versions()->max('version_number') + 1;
            $document->versions()->create([
                'version_number' => $next,
                'generated_srs' => $document->generated_srs,
                'created_by' => Auth::id(),
            ]);
        }

        $document->update(['generated_srs' => $version->generated_srs]);

        return $document->fresh();
    }

    public function reviewPayload(ReviewLink $link): array
    {
        $project = $link->project()->firstOrFail();
        $documents = SrsDocument::query()
            ->where('project_id', $project->id)
            ->whereNotNull('generated_srs')
            ->latest()
            ->get(['id', 'title', 'generated_srs', 'status', 'updated_at']);

        $requirements = Requirement::query()
            ->where('project_id', $project->id)
            ->with(['comments' => fn ($q) => $q->latest()->limit(50)])
            ->orderBy('code')
            ->get();

        $pipeline = null;
        if ($link->pipeline_run_id) {
            $run = PipelineRun::query()
                ->with(['tasks' => fn ($q) => $q->where('agent_role', 'developer')->orderBy('sort_order')])
                ->find($link->pipeline_run_id);

            if ($run) {
                $pipeline = [
                    'run_id' => $run->id,
                    'status' => $run->status->value,
                    'current_phase' => $run->current_phase?->value,
                    'approval_status' => $link->approval_status?->value ?? ReviewApprovalStatus::Pending->value,
                    'can_approve' => $run->status === PipelineRunStatus::AwaitingApproval
                        && ($link->approval_status ?? ReviewApprovalStatus::Pending) === ReviewApprovalStatus::Pending,
                    'tasks' => $run->tasks->map(fn ($t) => [
                        'id' => $t->id,
                        'title' => $t->title,
                        'description' => $t->description,
                        'agent_role' => $t->agent_role?->value,
                        'status' => $t->status?->value,
                        'sort_order' => $t->sort_order,
                    ])->values()->all(),
                ];
            }
        }

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
            ],
            'allow_comment' => $link->allow_comment,
            'documents' => $documents,
            'requirements' => $requirements,
            'pipeline' => $pipeline,
        ];
    }

    public function approvePipelineFromReview(string $token, ?string $approverName = null): PipelineRun
    {
        $link = $this->findReviewByToken($token);
        if (! $link || ! $link->pipeline_run_id) {
            throw new RuntimeException('Review link is not tied to a pipeline run.');
        }

        $run = PipelineRun::query()->find($link->pipeline_run_id);
        if (! $run) {
            throw new RuntimeException('Pipeline run not found.');
        }

        if ($run->status !== PipelineRunStatus::AwaitingApproval) {
            throw new RuntimeException('Pipeline run is not awaiting approval.');
        }

        /** @var PipelineOrchestrator $orchestrator */
        $orchestrator = app(PipelineOrchestrator::class);

        return $orchestrator->approve($run, null, $approverName ?: 'Stakeholder');
    }
}
