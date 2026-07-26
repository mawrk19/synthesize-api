<?php

namespace App\Modules\Orchestration\Services;

use App\Modules\Orchestration\Models\ProjectRepository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubRepositoryService
{
    public function resolveToken(ProjectRepository $repository): string
    {
        $token = $repository->getDecryptedToken()
            ?: (string) config('services.github.default_token');

        if (blank($token)) {
            throw new RuntimeException('GitHub token is not configured for this project.');
        }

        return $token;
    }

    public function resolveTokenOrFail(?string $plainToken): string
    {
        $token = filled($plainToken) ? $plainToken : (string) config('services.github.default_token');

        if (blank($token)) {
            throw new RuntimeException('GitHub token is not configured for this project.');
        }

        return $token;
    }

    public function clientForToken(string $token): PendingRequest
    {
        $baseUrl = rtrim((string) config('services.github.api_base_url'), '/');

        return Http::baseUrl($baseUrl)
            ->withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(60);
    }

    public function client(ProjectRepository $repository): PendingRequest
    {
        return $this->clientForToken($this->resolveToken($repository));
    }

    public function assertCanPushToRepository(ProjectRepository $repository): void
    {
        $response = $this->client($repository)
            ->get("/repos/{$repository->owner}/{$repository->repo}");

        if (! $response->successful()) {
            throw new RuntimeException($this->formatApiError($response, 'access this repository'));
        }

        if ($response->json('permissions.push') !== true) {
            throw new RuntimeException($this->tokenWriteAccessHelp($repository));
        }
    }

    public function formatApiError(\Illuminate\Http\Client\Response $response, string $action): string
    {
        $message = (string) ($response->json('message') ?? 'GitHub API request failed');
        $status = $response->status();

        if ($status === 403 && str_contains($message, 'personal access token')) {
            return 'GitHub rejected the token while trying to '.$action.'. '.$this->tokenWriteAccessHelp();
        }

        if ($status === 404) {
            return "GitHub could not find the repository while trying to {$action}. Check the owner, repo name, and token access.";
        }

        return "GitHub error while trying to {$action}: {$message}";
    }

    private function tokenWriteAccessHelp(?ProjectRepository $repository = null): string
    {
        $repoHint = $repository
            ? " for {$repository->owner}/{$repository->repo}"
            : '';

        return 'The PAT needs write access'.$repoHint.'. '
            .'Classic token: enable the repo scope (full control of private repositories). '
            .'Fine-grained token: grant this repository access with Contents set to Read and write, '
            .'and authorize SSO for the org if applicable. '
            .'For empty repositories, create an initial README on GitHub first or use a token with push permission.';
    }

    /**
     * @return array{owner: string, repo: string, html_url: string, default_branch: string}
     */
    public function createRepository(
        string $token,
        string $owner,
        string $name,
        bool $private = false,
        ?string $description = null,
    ): array {
        $client = $this->clientForToken($token);
        $payload = [
            'name' => $name,
            'private' => $private,
            'auto_init' => true,
        ];

        if (filled($description)) {
            $payload['description'] = $description;
        }

        $userResponse = $client->get('/user');
        if (! $userResponse->successful()) {
            throw new RuntimeException('Failed to resolve GitHub user: '.$userResponse->body());
        }

        $login = (string) $userResponse->json('login');
        $endpoint = strcasecmp($owner, $login) === 0
            ? '/user/repos'
            : "/orgs/{$owner}/repos";

        $response = $client->post($endpoint, $payload);

        if ($response->status() === 422) {
            throw new RuntimeException('Repository already exists or the name is invalid.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Failed to create GitHub repository: '.$response->body());
        }

        return [
            'owner' => (string) ($response->json('owner.login') ?? $owner),
            'repo' => (string) $response->json('name'),
            'html_url' => (string) $response->json('html_url'),
            'default_branch' => (string) ($response->json('default_branch') ?? 'main'),
        ];
    }

    public function ensureRepositoryInitialized(ProjectRepository $repository): ProjectRepository
    {
        $repoResponse = $this->client($repository)
            ->get("/repos/{$repository->owner}/{$repository->repo}");

        if (! $repoResponse->successful()) {
            throw new RuntimeException($this->formatApiError($repoResponse, 'fetch the repository'));
        }

        $githubDefaultBranch = $repoResponse->json('default_branch');
        $branch = filled($githubDefaultBranch)
            ? (string) $githubDefaultBranch
            : ($repository->default_branch ?: 'main');

        if (filled($githubDefaultBranch) && $githubDefaultBranch !== $repository->default_branch) {
            $repository->update(['default_branch' => $githubDefaultBranch]);
            $repository->refresh();
            $branch = (string) $githubDefaultBranch;
        }

        $refResponse = $this->client($repository)
            ->get("/repos/{$repository->owner}/{$repository->repo}/git/ref/heads/{$branch}");

        if ($refResponse->successful()) {
            return $repository;
        }

        $message = (string) ($refResponse->json('message') ?? $refResponse->body());
        $isEmpty = $refResponse->status() === 409 && str_contains($message, 'empty');

        if (! $isEmpty) {
            throw new RuntimeException($this->formatApiError($refResponse, 'resolve the default branch'));
        }

        $this->assertCanPushToRepository($repository);
        $this->initializeEmptyRepository($repository, $branch);

        $repoResponse = $this->client($repository)
            ->get("/repos/{$repository->owner}/{$repository->repo}");

        if ($repoResponse->successful() && filled($repoResponse->json('default_branch'))) {
            $actualBranch = (string) $repoResponse->json('default_branch');
            if ($actualBranch !== $repository->default_branch) {
                $repository->update(['default_branch' => $actualBranch]);
            }
        }

        return $repository->fresh();
    }

    private function initializeEmptyRepository(ProjectRepository $repository, string $branch): void
    {
        $repoResponse = $this->client($repository)
            ->get("/repos/{$repository->owner}/{$repository->repo}");

        $repoName = $repoResponse->successful()
            ? (string) ($repoResponse->json('name') ?? $repository->repo)
            : $repository->repo;

        $readme = "# {$repoName}\n\nInitialized by Synthesize.\n";

        $payload = [
            'message' => 'Initial commit',
            'content' => base64_encode($readme),
        ];

        $response = $this->client($repository)->put(
            "/repos/{$repository->owner}/{$repository->repo}/contents/README.md",
            $payload,
        );

        if (! $response->successful()) {
            throw new RuntimeException($this->formatApiError($response, 'initialize the empty repository'));
        }
    }

    /** @return array{sha: string, tree: list<array{path: string, type: string, sha: string}>} */
    public function getTree(ProjectRepository $repository, ?string $branch = null): array
    {
        $repository = $this->ensureRepositoryInitialized($repository);
        $branch ??= $repository->default_branch;
        $ref = $this->client($repository)
            ->get("/repos/{$repository->owner}/{$repository->repo}/git/ref/heads/{$branch}");

        if (! $ref->successful()) {
            throw new RuntimeException('Failed to resolve branch ref: '.$ref->body());
        }

        $commitSha = (string) data_get($ref->json(), 'object.sha');
        $commit = $this->client($repository)
            ->get("/repos/{$repository->owner}/{$repository->repo}/git/commits/{$commitSha}");

        if (! $commit->successful()) {
            throw new RuntimeException('Failed to resolve commit: '.$commit->body());
        }

        $treeSha = (string) data_get($commit->json(), 'tree.sha');
        $tree = $this->client($repository)
            ->get("/repos/{$repository->owner}/{$repository->repo}/git/trees/{$treeSha}", [
                'recursive' => 1,
            ]);

        if (! $tree->successful()) {
            throw new RuntimeException('Failed to fetch repository tree: '.$tree->body());
        }

        $paths = collect($tree->json('tree') ?? [])
            ->filter(fn (array $item) => ($item['type'] ?? '') === 'blob')
            ->map(fn (array $item) => [
                'path' => (string) $item['path'],
                'type' => (string) $item['type'],
                'sha' => (string) $item['sha'],
            ]);

        $basePath = trim((string) $repository->base_path, '/');
        if ($basePath !== '') {
            $paths = $paths->filter(
                fn (array $item) => str_starts_with($item['path'], $basePath.'/') || $item['path'] === $basePath
            );
        }

        return [
            'sha' => $commitSha,
            'tree' => $paths->values()->all(),
        ];
    }

    public function getFileContent(ProjectRepository $repository, string $path, ?string $ref = null): ?string
    {
        $query = $ref ? ['ref' => $ref] : [];
        $response = $this->client($repository)
            ->get("/repos/{$repository->owner}/{$repository->repo}/contents/{$path}", $query);

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException("Failed to fetch file {$path}: ".$response->body());
        }

        $content = (string) $response->json('content');
        $encoding = (string) $response->json('encoding');

        if ($encoding === 'base64') {
            return base64_decode(str_replace("\n", '', $content), true) ?: null;
        }

        return $content;
    }

    public function createBranch(ProjectRepository $repository, string $branchName, string $fromSha): void
    {
        $response = $this->client($repository)
            ->post("/repos/{$repository->owner}/{$repository->repo}/git/refs", [
                'ref' => "refs/heads/{$branchName}",
                'sha' => $fromSha,
            ]);

        if ($response->status() === 422 && str_contains((string) $response->body(), 'Reference already exists')) {
            return;
        }

        if (! $response->successful()) {
            throw new RuntimeException('Failed to create branch: '.$response->body());
        }
    }

    /**
     * @param  list<array{path: string, content: string, message?: string}>  $files
     */
    public function commitFiles(
        ProjectRepository $repository,
        string $branchName,
        array $files,
        string $commitMessage,
    ): string {
        $latestSha = null;

        foreach ($files as $file) {
            $path = $file['path'];
            $existing = $this->client($repository)
                ->get("/repos/{$repository->owner}/{$repository->repo}/contents/{$path}", [
                    'ref' => $branchName,
                ]);

            $payload = [
                'message' => $file['message'] ?? $commitMessage,
                'content' => base64_encode($file['content']),
                'branch' => $branchName,
            ];

            if ($existing->successful() && filled($existing->json('sha'))) {
                $payload['sha'] = $existing->json('sha');
            }

            $response = $this->client($repository)
                ->put("/repos/{$repository->owner}/{$repository->repo}/contents/{$path}", $payload);

            if (! $response->successful()) {
                throw new RuntimeException("Failed to commit {$path}: ".$response->body());
            }

            $latestSha = (string) data_get($response->json(), 'commit.sha');
        }

        if (! $latestSha) {
            throw new RuntimeException('No files were committed.');
        }

        return $latestSha;
    }

    /**
     * @return array{number: int, url: string, html_url: string}
     */
    public function openPullRequest(
        ProjectRepository $repository,
        string $headBranch,
        string $title,
        string $body,
    ): array {
        $response = $this->client($repository)
            ->post("/repos/{$repository->owner}/{$repository->repo}/pulls", [
                'title' => $title,
                'head' => $headBranch,
                'base' => $repository->default_branch,
                'body' => $body,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to open pull request: '.$response->body());
        }

        return [
            'number' => (int) $response->json('number'),
            'url' => (string) $response->json('url'),
            'html_url' => (string) $response->json('html_url'),
        ];
    }

    /**
     * @return array{conclusion: string|null, total: int, failed: int}
     */
    public function getCheckRunsSummary(ProjectRepository $repository, string $commitSha): array
    {
        $response = $this->client($repository)
            ->get("/repos/{$repository->owner}/{$repository->repo}/commits/{$commitSha}/check-runs");

        if (! $response->successful()) {
            return ['conclusion' => null, 'total' => 0, 'failed' => 0];
        }

        $runs = collect($response->json('check_runs') ?? []);
        $failed = $runs->filter(fn (array $run) => in_array($run['conclusion'] ?? null, ['failure', 'timed_out', 'cancelled'], true))->count();
        $pending = $runs->filter(fn (array $run) => ($run['status'] ?? '') !== 'completed')->count();

        $conclusion = match (true) {
            $runs->isEmpty() => null,
            $failed > 0 => 'failure',
            $pending > 0 => 'pending',
            default => 'success',
        };

        return [
            'conclusion' => $conclusion,
            'total' => $runs->count(),
            'failed' => $failed,
        ];
    }

    public function compareDiff(ProjectRepository $repository, string $base, string $head): string
    {
        $response = $this->client($repository)
            ->get("/repos/{$repository->owner}/{$repository->repo}/compare/{$base}...{$head}");

        if (! $response->successful()) {
            return '';
        }

        $files = collect($response->json('files') ?? []);

        return $files
            ->map(function (array $file): string {
                $patch = (string) ($file['patch'] ?? '');
                $path = (string) ($file['filename'] ?? 'unknown');

                return "--- a/{$path}\n+++ b/{$path}\n{$patch}";
            })
            ->implode("\n\n");
    }
}
