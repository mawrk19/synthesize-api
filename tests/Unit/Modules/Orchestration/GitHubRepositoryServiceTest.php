<?php

namespace Tests\Unit\Modules\Orchestration;

use App\Modules\Iam\Models\UserModel;
use App\Modules\Orchestration\Models\ProjectRepository;
use App\Modules\Orchestration\Services\GitHubRepositoryService;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubRepositoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_repository_initialized_creates_readme_on_empty_repo(): void
    {
        config(['services.github.default_token' => 'test-token', 'services.github.api_base_url' => 'https://api.github.com']);

        $user = UserModel::factory()->create();
        $project = Project::query()->create([
            'user_id' => $user->id,
            'name' => 'Empty Repo Project',
            'description' => 'Test',
            'status' => 'active',
        ]);
        $repository = ProjectRepository::query()->create([
            'project_id' => $project->id,
            'provider' => 'github',
            'owner' => 'mawrk19',
            'repo' => 'test-repo',
            'default_branch' => 'main',
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            $method = $request->method();

            if (str_contains($url, '/git/ref/heads/main') && $method === 'GET') {
                return Http::response([
                    'message' => 'Git Repository is empty.',
                ], 409);
            }

            if (
                str_contains($url, '/repos/mawrk19/test-repo')
                && ! str_contains($url, '/git/')
                && ! str_contains($url, '/contents/')
                && $method === 'GET'
            ) {
                return Http::response([
                    'name' => 'test-repo',
                    'default_branch' => 'main',
                    'permissions' => ['push' => true, 'pull' => true],
                ]);
            }

            if (str_contains($url, '/contents/README.md') && $method === 'PUT') {
                return Http::response([
                    'commit' => ['sha' => 'init-commit-sha'],
                    'content' => ['sha' => 'readme-sha'],
                ], 201);
            }

            return Http::response(['message' => 'unhandled '.$method.' '.$url], 500);
        });

        /** @var GitHubRepositoryService $service */
        $service = app(GitHubRepositoryService::class);
        $initialized = $service->ensureRepositoryInitialized($repository);

        $this->assertSame('main', $initialized->default_branch);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/contents/README.md'));
    }

    public function test_get_tree_initializes_empty_repository_before_resolving_ref(): void
    {
        config(['services.github.default_token' => 'test-token', 'services.github.api_base_url' => 'https://api.github.com']);

        $user = UserModel::factory()->create();
        $project = Project::query()->create([
            'user_id' => $user->id,
            'name' => 'Empty Repo Project',
            'description' => 'Test',
            'status' => 'active',
        ]);
        $repository = ProjectRepository::query()->create([
            'project_id' => $project->id,
            'provider' => 'github',
            'owner' => 'mawrk19',
            'repo' => 'test-repo',
            'default_branch' => 'main',
        ]);

        $refChecks = 0;

        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$refChecks) {
            $url = $request->url();
            $method = $request->method();

            if (str_contains($url, '/git/ref/heads/main') && $method === 'GET') {
                $refChecks++;
                if ($refChecks === 1) {
                    return Http::response(['message' => 'Git Repository is empty.'], 409);
                }

                return Http::response(['object' => ['sha' => 'base-commit-sha']]);
            }

            if (
                str_contains($url, '/repos/mawrk19/test-repo')
                && ! str_contains($url, '/git/')
                && ! str_contains($url, '/contents/')
                && $method === 'GET'
            ) {
                return Http::response([
                    'name' => 'test-repo',
                    'default_branch' => 'main',
                    'permissions' => ['push' => true, 'pull' => true],
                ]);
            }

            if (str_contains($url, '/contents/README.md') && $method === 'PUT') {
                return Http::response([
                    'commit' => ['sha' => 'init-commit-sha'],
                    'content' => ['sha' => 'readme-sha'],
                ], 201);
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

            return Http::response(['message' => 'unhandled '.$method.' '.$url], 500);
        });

        /** @var GitHubRepositoryService $service */
        $service = app(GitHubRepositoryService::class);
        $tree = $service->getTree($repository);

        $this->assertSame('base-commit-sha', $tree['sha']);
        $this->assertCount(1, $tree['tree']);
        $this->assertSame('README.md', $tree['tree'][0]['path']);
    }
}
