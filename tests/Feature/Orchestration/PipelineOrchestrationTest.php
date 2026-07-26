<?php

namespace Tests\Feature\Orchestration;

use App\Modules\Core\Services\AiCompletionService;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Iam\Models\UserModel;
use App\Modules\Orchestration\Enums\AgentRole;
use App\Modules\Orchestration\Enums\PipelineRunStatus;
use App\Modules\Orchestration\Enums\PipelineTaskStatus;
use App\Modules\Orchestration\Enums\PrStatus;
use App\Modules\Orchestration\Models\PipelineRun;
use App\Modules\Orchestration\Models\PipelineTask;
use App\Modules\Orchestration\Models\ProjectRepository;
use App\Modules\Orchestration\Models\TaskCodeChange;
use App\Modules\Orchestration\Services\Agents\DeveloperAgent;
use App\Modules\Orchestration\Services\PipelineOrchestrator;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Requirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PipelineOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private UserModel $user;

    private Project $project;

    private SrsDocument $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = UserModel::factory()->create();
        $this->project = Project::query()->create([
            'user_id' => $this->user->id,
            'name' => 'Pipeline Test Project',
            'description' => 'Test',
            'status' => 'active',
        ]);
        $this->document = SrsDocument::query()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'title' => 'Inventory SRS',
            'source_notes' => 'Need inventory endpoints',
            'status' => DocumentStatus::Completed,
            'generated_srs' => "# Inventory\n\nUsers can manage stock levels.",
        ]);

        Requirement::query()->create([
            'project_id' => $this->project->id,
            'srs_document_id' => $this->document->id,
            'type' => 'fr',
            'code' => 'FR-001',
            'title' => 'List stock levels',
            'body' => 'API returns stock by warehouse',
            'gherkin' => "Given a warehouse\nWhen I list stock\nThen I see quantities",
            'priority' => 'must',
        ]);
    }

    public function test_unauthenticated_start_returns_401(): void
    {
        $this->postJson("/api/documents/{$this->document->id}/pipeline/start")
            ->assertUnauthorized();
    }

    public function test_other_users_document_returns_404(): void
    {
        $other = UserModel::factory()->create();

        $this->actingAs($other)
            ->postJson("/api/documents/{$this->document->id}/pipeline/start")
            ->assertNotFound();
    }

    public function test_planner_creates_tasks_and_awaits_approval(): void
    {
        $this->mock(AiCompletionService::class, function ($mock) {
            $mock->shouldReceive('complete')
                ->once()
                ->andReturn(json_encode([
                    [
                        'title' => 'Add stock list endpoint',
                        'description' => 'Implement GET /stock',
                        'requirement_code' => 'FR-001',
                        'sort_order' => 1,
                        'prompt_template' => 'Implement stock listing',
                        'files_hint' => ['api-main/routes/api.php'],
                    ],
                ]));
        });

        $response = $this->actingAs($this->user)
            ->postJson("/api/documents/{$this->document->id}/pipeline/start");

        $response->assertCreated();

        $run = PipelineRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame(PipelineRunStatus::AwaitingApproval, $run->status);

        $tasks = PipelineTask::query()->where('pipeline_run_id', $run->id)->get();
        $this->assertCount(3, $tasks);
        $this->assertTrue($tasks->contains(fn ($t) => $t->agent_role === AgentRole::Developer));
        $this->assertTrue($tasks->contains(fn ($t) => $t->agent_role === AgentRole::Tester));
        $this->assertTrue($tasks->contains(fn ($t) => $t->agent_role === AgentRole::Reviewer));

        $this->assertDatabaseHas('review_links', [
            'project_id' => $this->project->id,
            'pipeline_run_id' => $run->id,
        ]);
    }

    public function test_hitl_blocks_execution_until_approve(): void
    {
        $this->mock(AiCompletionService::class, function ($mock) {
            $mock->shouldReceive('complete')->andReturn(json_encode([
                [
                    'title' => 'Task A',
                    'description' => 'Desc',
                    'requirement_code' => 'FR-001',
                    'sort_order' => 1,
                    'prompt_template' => 'Do it',
                    'files_hint' => [],
                ],
            ]));
        });

        $this->actingAs($this->user)
            ->postJson("/api/documents/{$this->document->id}/pipeline/start")
            ->assertCreated();

        $run = PipelineRun::query()->firstOrFail();
        $this->assertSame(PipelineRunStatus::AwaitingApproval, $run->status);

        /** @var PipelineOrchestrator $orchestrator */
        $orchestrator = app(PipelineOrchestrator::class);
        $orchestrator->tick($run->id);

        $run->refresh();
        $this->assertSame(PipelineRunStatus::AwaitingApproval, $run->status);
        $this->assertSame(
            0,
            PipelineTask::query()
                ->where('pipeline_run_id', $run->id)
                ->where('agent_role', AgentRole::Developer)
                ->where('status', '!=', PipelineTaskStatus::Pending->value)
                ->count()
        );

        // Prevent approve from auto-running developer (no repo configured).
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson("/api/projects/{$this->project->id}/pipeline-runs/{$run->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'executing');

        $run->refresh();
        $this->assertSame(PipelineRunStatus::Executing, $run->status);
        $this->assertNotNull($run->approved_at);
    }

    public function test_public_review_can_approve_pipeline(): void
    {
        $this->mock(AiCompletionService::class, function ($mock) {
            $mock->shouldReceive('complete')->andReturn(json_encode([
                [
                    'title' => 'Task A',
                    'description' => 'Desc',
                    'requirement_code' => 'FR-001',
                    'sort_order' => 1,
                    'prompt_template' => 'Do it',
                    'files_hint' => [],
                ],
            ]));
        });

        $this->actingAs($this->user)
            ->postJson("/api/documents/{$this->document->id}/pipeline/start")
            ->assertCreated();

        $run = PipelineRun::query()->firstOrFail();
        $link = \App\Modules\Collaboration\Models\ReviewLink::query()
            ->where('pipeline_run_id', $run->id)
            ->firstOrFail();

        $this->getJson("/api/review/{$link->token}")
            ->assertOk()
            ->assertJsonPath('data.pipeline.can_approve', true);

        Queue::fake();

        $this->postJson("/api/review/{$link->token}/approve-pipeline", [
            'approver_name' => 'Stakeholder Jane',
        ])->assertOk()
            ->assertJsonPath('data.status', 'executing');

        $run->refresh();
        $this->assertSame(PipelineRunStatus::Executing, $run->status);
        $link->refresh();
        $this->assertSame('approved', $link->approval_status->value);
    }

    public function test_developer_agent_is_idempotent_for_existing_code_change(): void
    {
        $run = PipelineRun::query()->create([
            'project_id' => $this->project->id,
            'srs_document_id' => $this->document->id,
            'status' => PipelineRunStatus::Executing,
            'current_phase' => AgentRole::Developer,
            'approved_at' => now(),
            'approved_by_user_id' => $this->user->id,
        ]);

        $task = PipelineTask::query()->create([
            'pipeline_run_id' => $run->id,
            'project_id' => $this->project->id,
            'sort_order' => 1,
            'title' => 'Existing PR task',
            'description' => 'Already done',
            'agent_role' => AgentRole::Developer,
            'status' => PipelineTaskStatus::Pending,
        ]);

        TaskCodeChange::query()->create([
            'pipeline_task_id' => $task->id,
            'branch_name' => 'synthesize/task-existing',
            'commit_sha' => 'abc123',
            'pr_number' => 42,
            'pr_url' => 'https://github.com/org/repo/pull/42',
            'pr_status' => PrStatus::Open,
            'unified_diff' => 'diff --git a/x b/x',
            'files_changed' => [['path' => 'x', 'action' => 'update']],
        ]);

        /** @var DeveloperAgent $agent */
        $agent = app(DeveloperAgent::class);
        $handled = $agent->executeNext($run->fresh());

        $this->assertTrue($handled);
        $task->refresh();
        $this->assertSame(PipelineTaskStatus::Review, $task->status);
        $this->assertSame(1, TaskCodeChange::query()->where('pipeline_task_id', $task->id)->count());
    }

    public function test_developer_agent_persists_pr_from_mocked_github(): void
    {
        config(['services.github.default_token' => 'test-token']);

        $repo = ProjectRepository::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'app',
            'default_branch' => 'main',
        ]);

        $run = PipelineRun::query()->create([
            'project_id' => $this->project->id,
            'srs_document_id' => $this->document->id,
            'status' => PipelineRunStatus::Executing,
            'current_phase' => AgentRole::Developer,
            'approved_at' => now(),
        ]);

        $task = PipelineTask::query()->create([
            'pipeline_run_id' => $run->id,
            'project_id' => $this->project->id,
            'sort_order' => 1,
            'title' => 'Add readme note',
            'description' => 'Create a synthesize note',
            'agent_role' => AgentRole::Developer,
            'status' => PipelineTaskStatus::Pending,
            'prompt_template' => 'Create synthesize/note.md',
        ]);

        $this->mock(AiCompletionService::class, function ($mock) {
            $mock->shouldReceive('complete')->andReturn(json_encode([
                'files' => [
                    [
                        'path' => 'synthesize/note.md',
                        'action' => 'create',
                        'content' => "# Note\n\nHello from test\n",
                    ],
                ],
            ]));
        });

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            $method = $request->method();

            if (
                str_contains($url, '/repos/acme/app')
                && ! str_contains($url, '/git/')
                && ! str_contains($url, '/contents/')
                && $method === 'GET'
            ) {
                return Http::response([
                    'name' => 'app',
                    'default_branch' => 'main',
                    'permissions' => ['push' => true, 'pull' => true],
                ]);
            }
            if (str_contains($url, '/git/ref/heads/main') && $method === 'GET') {
                return Http::response(['object' => ['sha' => 'base-commit-sha']]);
            }
            if (str_contains($url, '/git/commits/base-commit-sha')) {
                return Http::response(['tree' => ['sha' => 'tree-sha']]);
            }
            if (str_contains($url, '/git/trees/tree-sha')) {
                return Http::response([
                    'tree' => [
                        ['path' => 'README.md', 'type' => 'blob', 'sha' => 'file-sha'],
                    ],
                ]);
            }
            if (str_contains($url, '/git/refs') && $method === 'POST') {
                return Http::response(['ref' => 'refs/heads/synthesize/task-x'], 201);
            }
            if (str_contains($url, '/contents/') && $method === 'GET') {
                return Http::response(['message' => 'Not Found'], 404);
            }
            if (str_contains($url, '/contents/') && $method === 'PUT') {
                return Http::response([
                    'commit' => ['sha' => 'new-commit-sha'],
                    'content' => ['sha' => 'blob-sha'],
                ], 201);
            }
            if (str_contains($url, '/pulls') && $method === 'POST') {
                return Http::response([
                    'number' => 7,
                    'url' => 'https://api.github.com/repos/acme/app/pulls/7',
                    'html_url' => 'https://github.com/acme/app/pull/7',
                ], 201);
            }
            if (str_contains($url, '/compare/')) {
                return Http::response([
                    'files' => [
                        ['filename' => 'synthesize/note.md', 'patch' => '+# Note'],
                    ],
                ]);
            }

            return Http::response(['message' => 'unhandled '.$method.' '.$url], 500);
        });

        /** @var DeveloperAgent $agent */
        $agent = app(DeveloperAgent::class);
        $this->assertTrue($agent->executeNext($run->fresh()));

        $task->refresh();
        $this->assertSame(PipelineTaskStatus::Review, $task->status);

        $change = $task->codeChange;
        $this->assertNotNull($change);
        $this->assertSame(7, $change->pr_number);
        $this->assertSame('https://github.com/acme/app/pull/7', $change->pr_url);
        $this->assertNotEmpty($change->unified_diff);
    }

    public function test_repository_upsert_hides_token(): void
    {
        config(['services.github.api_base_url' => 'https://api.github.com']);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            $method = $request->method();

            if (
                str_contains($url, '/repos/acme/app')
                && ! str_contains($url, '/git/')
                && ! str_contains($url, '/contents/')
                && $method === 'GET'
            ) {
                return Http::response([
                    'name' => 'app',
                    'default_branch' => 'main',
                    'permissions' => ['push' => true, 'pull' => true],
                ]);
            }

            if (str_contains($url, '/git/ref/heads/main') && $method === 'GET') {
                return Http::response(['object' => ['sha' => 'base-commit-sha']]);
            }

            return Http::response(['message' => 'unhandled '.$method.' '.$url], 500);
        });

        $this->actingAs($this->user)
            ->putJson("/api/projects/{$this->project->id}/repository", [
                'owner' => 'acme',
                'repo' => 'app',
                'default_branch' => 'main',
                'token' => 'ghp_secret_token_value',
            ])
            ->assertOk()
            ->assertJsonPath('data.owner', 'acme')
            ->assertJsonPath('data.has_token', true)
            ->assertJsonMissing(['encrypted_token' => 'ghp_secret_token_value'])
            ->assertJsonMissingPath('data.encrypted_token')
            ->assertJsonMissingPath('data.token');

        $stored = ProjectRepository::query()->where('project_id', $this->project->id)->firstOrFail();
        $this->assertNotSame('ghp_secret_token_value', $stored->encrypted_token);
        $this->assertSame('ghp_secret_token_value', $stored->getDecryptedToken());
    }

    public function test_repository_upsert_returns_initialization_warning_when_token_cannot_push(): void
    {
        config(['services.github.api_base_url' => 'https://api.github.com']);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            $method = $request->method();

            if (
                str_contains($url, '/repos/acme/app')
                && ! str_contains($url, '/git/')
                && ! str_contains($url, '/contents/')
                && $method === 'GET'
            ) {
                return Http::response([
                    'name' => 'app',
                    'default_branch' => 'main',
                    'permissions' => ['push' => false, 'pull' => true],
                ]);
            }

            if (str_contains($url, '/git/ref/heads/main') && $method === 'GET') {
                return Http::response(['message' => 'Git Repository is empty.'], 409);
            }

            return Http::response(['message' => 'unhandled '.$method.' '.$url], 500);
        });

        $this->actingAs($this->user)
            ->putJson("/api/projects/{$this->project->id}/repository", [
                'owner' => 'acme',
                'repo' => 'app',
                'default_branch' => 'main',
                'token' => 'ghp_secret_token_value',
            ])
            ->assertOk()
            ->assertJsonPath('data.owner', 'acme')
            ->assertJsonPath('data.has_token', true)
            ->assertJsonPath('data.initialization_warning', fn ($value) => is_string($value) && str_contains($value, 'write access'));
    }
}
