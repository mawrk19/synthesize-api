<?php

namespace App\Modules\Projects\Services;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Projects\Jobs\ExtractContextTextJob;
use App\Modules\Projects\Models\ContextFile;
use App\Modules\Projects\Models\Project;
use App\Support\UploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class ContextFileService
{
    /** @return Collection<int, ContextFile> */
    public function listForProject(Project $project): Collection
    {
        return $project->contextFiles()->latest()->get();
    }

    public function upload(Project $project, UploadedFile $file): ContextFile
    {
        $path = UploadStorage::store($file, "projects/{$project->id}/context");

        $contextFile = ContextFile::query()->create([
            'project_id' => $project->id,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'storage_path' => $path,
            'status' => DocumentStatus::Pending,
        ]);

        ExtractContextTextJob::dispatch($contextFile->id);

        return $contextFile;
    }

    public function findForProject(Project $project, string $id): ?ContextFile
    {
        return ContextFile::query()
            ->where('project_id', $project->id)
            ->where('id', $id)
            ->first();
    }

    public function delete(Project $project, string $id): bool
    {
        $file = $this->findForProject($project, $id);

        if (! $file) {
            return false;
        }

        return (bool) $file->delete();
    }

    public function reextract(Project $project, string $id): ?ContextFile
    {
        $file = $this->findForProject($project, $id);

        if (! $file) {
            return null;
        }

        $file->update([
            'status' => DocumentStatus::Pending,
            'extracted_text' => null,
            'error_message' => null,
        ]);

        ExtractContextTextJob::dispatch($file->id);

        return $file->fresh();
    }

    /** @return list<string> */
    public function extractedBlocksForProject(Project $project, int $maxChars = 12000): array
    {
        $files = $project->contextFiles()
            ->where('status', DocumentStatus::Completed)
            ->whereNotNull('extracted_text')
            ->latest()
            ->get();

        $blocks = [];
        $used = 0;

        foreach ($files as $file) {
            $chunk = mb_substr((string) $file->extracted_text, 0, 4000);
            if ($this->looksUnusableContext($chunk)) {
                continue;
            }
            if ($used + mb_strlen($chunk) > $maxChars) {
                break;
            }
            $blocks[] = "### Context file: {$file->filename}\n\n{$chunk}";
            $used += mb_strlen($chunk);
        }

        return $blocks;
    }

    private function looksUnusableContext(string $text): bool
    {
        $sample = mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 400);
        if (mb_strlen($sample) < 40) {
            return false;
        }

        $tokens = preg_split('/\s+/', $sample) ?: [];
        if (count($tokens) < 20) {
            return false;
        }

        $singleChar = 0;
        foreach ($tokens as $token) {
            if (mb_strlen($token) === 1) {
                $singleChar++;
            }
        }

        return ($singleChar / count($tokens)) > 0.55;
    }
}
