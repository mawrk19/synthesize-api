<?php

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Jobs\GenerateSrsDocumentJob;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ContextFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class SrsDocumentService
{
    public function __construct(
        private readonly ContextFileService $contextFileService,
    ) {}

    /** @return Collection<int, SrsDocument> */
    public function listForCurrentUser(?string $projectId = null): Collection
    {
        $query = SrsDocument::query()
            ->where('user_id', Auth::id())
            ->latest();

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        return $query->get();
    }

    public function findForCurrentUser(string $id): ?SrsDocument
    {
        return SrsDocument::query()
            ->where('user_id', Auth::id())
            ->where('id', $id)
            ->first();
    }

    public function create(string $title, ?string $notes, ?UploadedFile $file, ?string $projectId = null): SrsDocument
    {
        $sourceNotes = $notes ?? '';
        $sourceFilename = null;

        if ($file) {
            $sourceFilename = $file->getClientOriginalName();
            $fileContents = $file->get();
            $sourceNotes = filled($sourceNotes)
                ? $sourceNotes."\n\n".$fileContents
                : $fileContents;
        }

        $project = $this->resolveProject($projectId);

        $contextBlocks = $this->contextFileService->extractedBlocksForProject($project);
        if ($contextBlocks !== []) {
            $sourceNotes = "## Existing System Context\n\n".implode("\n\n---\n\n", $contextBlocks)."\n\n## Notes\n\n".$sourceNotes;
        }

        $document = SrsDocument::query()->create([
            'user_id' => Auth::id(),
            'project_id' => $project->id,
            'title' => $title,
            'source_notes' => $sourceNotes,
            'source_filename' => $sourceFilename,
            'status' => DocumentStatus::Pending,
        ]);

        GenerateSrsDocumentJob::dispatch($document->id);

        return $document;
    }

    public function regenerate(string $id): ?SrsDocument
    {
        $document = $this->findForCurrentUser($id);

        if (! $document) {
            return null;
        }

        if (filled($document->generated_srs)) {
            $this->snapshotVersion($document);
        }

        $this->refreshEmbeddedContext($document);

        $document->update([
            'status' => DocumentStatus::Pending,
            'generated_srs' => null,
            'error_message' => null,
        ]);

        GenerateSrsDocumentJob::dispatch($document->id);

        return $document->fresh();
    }

    /**
     * Replace the snapshotted "Existing System Context" block with the latest
     * extracted context-file text, preserving the user's notes / intake body.
     */
    public function refreshEmbeddedContext(SrsDocument $document): void
    {
        if (! $document->project_id) {
            return;
        }

        $project = Project::query()->find($document->project_id);
        if (! $project) {
            return;
        }

        $notes = (string) $document->source_notes;
        $userSection = $this->extractUserNotesSection($notes);
        $contextBlocks = $this->contextFileService->extractedBlocksForProject($project);

        if ($contextBlocks === []) {
            $document->update(['source_notes' => $userSection]);

            return;
        }

        $heading = str_contains($notes, "## Stakeholder Input\n")
            ? '## Stakeholder Input'
            : '## Notes';

        $document->update([
            'source_notes' => "## Existing System Context\n\n"
                .implode("\n\n---\n\n", $contextBlocks)
                ."\n\n{$heading}\n\n"
                .$userSection,
        ]);
    }

    private function extractUserNotesSection(string $notes): string
    {
        foreach (['## Notes', '## Stakeholder Input'] as $marker) {
            $pos = strpos($notes, $marker."\n");
            if ($pos === false) {
                $pos = strpos($notes, $marker."\r\n");
            }
            if ($pos !== false) {
                $after = substr($notes, $pos + strlen($marker));
                $after = ltrim($after, "\r\n");

                return trim($after);
            }
        }

        // No structured section — if the whole blob starts with context, drop it.
        if (str_starts_with(ltrim($notes), '## Existing System Context')) {
            return '';
        }

        return trim($notes);
    }

    public function updateTitle(string $id, string $title): ?SrsDocument
    {
        $document = $this->findForCurrentUser($id);

        if (! $document) {
            return null;
        }

        $document->update(['title' => $title]);

        return $document->fresh();
    }

    public function delete(string $id): bool
    {
        $document = $this->findForCurrentUser($id);

        if (! $document) {
            return false;
        }

        return (bool) $document->delete();
    }

    private function resolveProject(?string $projectId): Project
    {
        if ($projectId) {
            $project = Project::query()
                ->where('user_id', Auth::id())
                ->where('id', $projectId)
                ->first();

            if (! $project) {
                abort(404, 'Project not found');
            }

            return $project;
        }

        $project = Project::query()
            ->where('user_id', Auth::id())
            ->where('status', ProjectStatus::Active)
            ->latest()
            ->first();

        if ($project) {
            return $project;
        }

        return Project::query()->create([
            'user_id' => Auth::id(),
            'name' => 'Default Project',
            'description' => 'Auto-created project',
            'status' => ProjectStatus::Active,
        ]);
    }

    private function snapshotVersion(SrsDocument $document): void
    {
        $next = (int) $document->versions()->max('version_number') + 1;

        $document->versions()->create([
            'version_number' => $next,
            'generated_srs' => $document->generated_srs,
            'created_by' => Auth::id(),
        ]);
    }
}
