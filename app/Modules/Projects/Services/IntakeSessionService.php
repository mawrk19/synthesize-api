<?php

namespace App\Modules\Projects\Services;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Jobs\GenerateSrsDocumentJob;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Projects\Enums\IntakeSourceType;
use App\Modules\Projects\Enums\IntakeStatus;
use App\Modules\Projects\Jobs\StructureIntakeJob;
use App\Modules\Projects\Jobs\TranscribeAudioJob;
use App\Modules\Projects\Models\IntakeSession;
use App\Modules\Projects\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class IntakeSessionService
{
    public function __construct(
        private readonly ContextFileService $contextFileService,
    ) {}

    /** @return Collection<int, IntakeSession> */
    public function listForProject(Project $project): Collection
    {
        return $project->intakeSessions()->latest()->get();
    }

    public function findForProject(Project $project, string $id): ?IntakeSession
    {
        return IntakeSession::query()
            ->where('project_id', $project->id)
            ->where('id', $id)
            ->first();
    }

    public function createBrainDump(Project $project, ?string $title = null, ?string $rawContent = null): IntakeSession
    {
        return IntakeSession::query()->create([
            'project_id' => $project->id,
            'title' => $title ?? 'Brain dump',
            'source_type' => IntakeSourceType::BrainDump,
            'raw_content' => $rawContent ?? '',
            'status' => IntakeStatus::Draft,
        ]);
    }

    public function update(Project $project, string $id, array $data): ?IntakeSession
    {
        $session = $this->findForProject($project, $id);

        if (! $session) {
            return null;
        }

        $session->update([
            'title' => $data['title'] ?? $session->title,
            'raw_content' => array_key_exists('raw_content', $data) ? $data['raw_content'] : $session->raw_content,
        ]);

        return $session->fresh();
    }

    public function structure(Project $project, string $id): ?IntakeSession
    {
        $session = $this->findForProject($project, $id);

        if (! $session) {
            return null;
        }

        $session->update([
            'status' => IntakeStatus::Pending,
            'error_message' => null,
        ]);

        StructureIntakeJob::dispatch($session->id);

        return $session->fresh();
    }

    public function createFromTranscript(Project $project, string $transcript, ?string $title = null): IntakeSession
    {
        return IntakeSession::query()->create([
            'project_id' => $project->id,
            'title' => $title ?? 'Meeting transcript',
            'source_type' => IntakeSourceType::Transcript,
            'raw_content' => $transcript,
            'status' => IntakeStatus::Draft,
        ]);
    }

    public function createFromAudio(Project $project, UploadedFile $file, ?string $title = null): IntakeSession
    {
        $path = $file->store("projects/{$project->id}/audio", 'local');

        $session = IntakeSession::query()->create([
            'project_id' => $project->id,
            'title' => $title ?? $file->getClientOriginalName(),
            'source_type' => IntakeSourceType::Audio,
            'raw_content' => null,
            'audio_path' => $path,
            'status' => IntakeStatus::Pending,
        ]);

        TranscribeAudioJob::dispatch($session->id);

        return $session;
    }

    public function generateSrs(Project $project, string $sessionId, ?string $title = null): ?SrsDocument
    {
        $session = $this->findForProject($project, $sessionId);

        if (! $session) {
            return null;
        }

        $notes = $this->buildNotesFromSession($session);
        $contextBlocks = $this->contextFileService->extractedBlocksForProject($project);

        if ($contextBlocks !== []) {
            $notes = "## Existing System Context\n\n".implode("\n\n---\n\n", $contextBlocks)."\n\n## Stakeholder Input\n\n".$notes;
        }

        $document = SrsDocument::query()->create([
            'user_id' => Auth::id(),
            'project_id' => $project->id,
            'title' => $title ?? ($session->title ?: 'SRS from intake'),
            'source_notes' => $notes,
            'source_filename' => null,
            'status' => DocumentStatus::Pending,
        ]);

        GenerateSrsDocumentJob::dispatch($document->id);

        $session->update(['status' => IntakeStatus::Completed]);

        return $document;
    }

    private function buildNotesFromSession(IntakeSession $session): string
    {
        $parts = [];

        if (filled($session->raw_content)) {
            $parts[] = trim((string) $session->raw_content);
        }

        if (is_array($session->structured_draft) && $session->structured_draft !== []) {
            $draft = $session->structured_draft;
            $sections = [];

            foreach (['functional' => 'Functional', 'nonFunctional' => 'Non-Functional', 'businessRules' => 'Business Rules'] as $key => $label) {
                $items = $draft[$key] ?? [];
                if (! is_array($items) || $items === []) {
                    continue;
                }
                $bullet = implode("\n", array_map(fn ($i) => '- '.(is_string($i) ? $i : json_encode($i)), $items));
                $sections[] = "### {$label}\n{$bullet}";
            }

            if ($sections !== []) {
                $parts[] = "## Structured Categories\n\n".implode("\n\n", $sections);
            }
        }

        return implode("\n\n", $parts);
    }
}
