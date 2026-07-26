<?php

namespace App\Modules\Diagrams\Services;

use App\Modules\Core\Services\AiCompletionService;
use App\Modules\Diagrams\Enums\DiagramType;
use App\Modules\Diagrams\Jobs\GenerateDiagramJob;
use App\Modules\Diagrams\Models\Diagram;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Collection;

class DiagramService
{
    public function __construct(
        private readonly AiCompletionService $ai,
    ) {}

    /** @return Collection<int, Diagram> */
    public function listForProject(Project $project): Collection
    {
        return Diagram::query()
            ->where('project_id', $project->id)
            ->latest()
            ->get();
    }

    public function findForProject(Project $project, string $id): ?Diagram
    {
        return Diagram::query()
            ->where('project_id', $project->id)
            ->where('id', $id)
            ->first();
    }

    public function create(Project $project, DiagramType $type, string $title, ?string $srsDocumentId = null): Diagram
    {
        $diagram = Diagram::query()->create([
            'project_id' => $project->id,
            'srs_document_id' => $srsDocumentId,
            'type' => $type,
            'title' => $title,
            'status' => DocumentStatus::Pending,
        ]);

        GenerateDiagramJob::dispatch($diagram->id);

        return $diagram;
    }

    public function updateSource(Project $project, string $id, string $mermaidSource): ?Diagram
    {
        $diagram = $this->findForProject($project, $id);

        if (! $diagram) {
            return null;
        }

        $diagram->update([
            'mermaid_source' => $mermaidSource,
            'status' => DocumentStatus::Completed,
            'error_message' => null,
        ]);

        return $diagram->fresh();
    }

    public function regenerate(Project $project, string $id): ?Diagram
    {
        $diagram = $this->findForProject($project, $id);

        if (! $diagram) {
            return null;
        }

        $diagram->update([
            'status' => DocumentStatus::Pending,
            'error_message' => null,
        ]);

        GenerateDiagramJob::dispatch($diagram->id);

        return $diagram->fresh();
    }

    public function delete(Project $project, string $id): bool
    {
        $diagram = $this->findForProject($project, $id);

        if (! $diagram) {
            return false;
        }

        return (bool) $diagram->delete();
    }

    /** @return Collection<int, Diagram> */
    public function generateFromDocument(SrsDocument $document, array $types = ['sequence', 'erd']): Collection
    {
        $created = collect();

        foreach ($types as $typeValue) {
            $type = DiagramType::from($typeValue);
            $title = ucfirst($typeValue).' — '.$document->title;
            $created->push($this->create($document->project, $type, $title, $document->id));
        }

        return $created;
    }

    public function generateMermaid(Diagram $diagram, string $srsContent): string
    {
        if (! $this->ai->isConfigured()) {
            return $this->fallbackMermaid($diagram->type);
        }

        $typeLabel = $diagram->type->value;
        $system = <<<PROMPT
You are a Lead System Analyst producing Mermaid.js diagrams.
Generate a valid {$typeLabel} diagram from the SRS.
Rules:
- Output ONLY Mermaid source (no markdown fences, no commentary).
- Use valid Mermaid syntax for {$typeLabel} diagrams.
- Include error/edge paths where relevant (especially sequence diagrams).
- Prefer clear participant/entity labels from the domain.
PROMPT;

        $content = $this->ai->complete($system, "Diagram title: {$diagram->title}\n\nSRS:\n{$srsContent}", [
            'temperature' => 0.1,
        ]);

        $content = trim($content);
        $content = preg_replace('/^```(?:mermaid)?\s*|\s*```$/s', '', $content) ?? $content;

        return trim($content);
    }

    private function fallbackMermaid(DiagramType $type): string
    {
        return match ($type) {
            DiagramType::Sequence => <<<'M'
sequenceDiagram
    participant User
    participant API
    participant DB
    User->>API: Submit request
    API->>DB: Persist
    DB-->>API: OK
    API-->>User: 201 Created
M,
            DiagramType::Erd => <<<'M'
erDiagram
    ENTITY_A ||--o{ ENTITY_B : has
    ENTITY_A {
        uuid id PK
        string name
    }
    ENTITY_B {
        uuid id PK
        uuid entity_a_id FK
    }
M,
            DiagramType::Flowchart => <<<'M'
flowchart TD
    Start([Start]) --> Validate{Valid?}
    Validate -->|Yes| Process[Process]
    Validate -->|No| Error[Return 422]
    Process --> Done([Done])
M,
            DiagramType::State => <<<'M'
stateDiagram-v2
    [*] --> Draft
    Draft --> Active
    Active --> Archived
    Archived --> [*]
M,
        };
    }
}
