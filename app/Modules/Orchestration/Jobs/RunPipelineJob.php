<?php

namespace App\Modules\Orchestration\Jobs;

use App\Modules\Orchestration\Enums\PipelineRunStatus;
use App\Modules\Orchestration\Models\PipelineRun;
use App\Modules\Orchestration\Services\PipelineOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunPipelineJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    public function __construct(
        public string $runId,
    ) {}

    public function handle(PipelineOrchestrator $orchestrator): void
    {
        $orchestrator->tick($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        $run = PipelineRun::query()->find($this->runId);
        if (! $run) {
            return;
        }

        if ($run->status->isTerminal()) {
            return;
        }

        Log::error('Pipeline job failed', [
            'run_id' => $this->runId,
            'message' => $exception?->getMessage(),
        ]);

        $run->update([
            'status' => PipelineRunStatus::Failed,
            'error_message' => $exception?->getMessage() ?? 'Pipeline orchestration failed.',
        ]);
    }
}
