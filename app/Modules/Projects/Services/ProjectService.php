<?php

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ProjectService
{
    /** @return Collection<int, Project> */
    public function listForCurrentUser(): Collection
    {
        return Project::query()
            ->where('user_id', Auth::id())
            ->withCount(['documents', 'requirements', 'contextFiles', 'intakeSessions'])
            ->latest()
            ->get();
    }

    public function findForCurrentUser(string $id): ?Project
    {
        return Project::query()
            ->where('user_id', Auth::id())
            ->where('id', $id)
            ->first();
    }

    public function create(string $name, ?string $description = null): Project
    {
        return Project::query()->create([
            'user_id' => Auth::id(),
            'name' => $name,
            'description' => $description,
            'status' => ProjectStatus::Active,
        ]);
    }

    public function update(string $id, array $data): ?Project
    {
        $project = $this->findForCurrentUser($id);

        if (! $project) {
            return null;
        }

        $project->update(array_filter([
            'name' => $data['name'] ?? null,
            'description' => array_key_exists('description', $data) ? $data['description'] : $project->description,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null));

        return $project->fresh();
    }

    public function delete(string $id): bool
    {
        $project = $this->findForCurrentUser($id);

        if (! $project) {
            return false;
        }

        return (bool) $project->delete();
    }
}
