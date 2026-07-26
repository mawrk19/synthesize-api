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

    public function client(ProjectRepository $repository): PendingRequest
    {
        $baseUrl = rtrim((string) config('services.github.api_base_url'), '/');

        return Http::baseUrl($baseUrl)
            ->withToken($this->resolveToken($repository))
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(60);
    }

    /** @return array{sha: string, tree: list<array{path: string, type: string, sha: string}>} */
    public function getTree(ProjectRepository $repository, ?string $branch = null): array
    {
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
