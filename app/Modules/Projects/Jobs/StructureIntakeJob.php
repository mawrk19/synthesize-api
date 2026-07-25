<?php

namespace App\Modules\Projects\Jobs;

use App\Modules\Core\Services\AiCompletionService;
use App\Modules\Projects\Enums\IntakeStatus;
use App\Modules\Projects\Models\IntakeSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class StructureIntakeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public string $intakeSessionId,
    ) {}

    public function handle(AiCompletionService $ai): void
    {
        $session = IntakeSession::query()->find($this->intakeSessionId);

        if (! $session) {
            return;
        }

        $session->update([
            'status' => IntakeStatus::Processing,
            'error_message' => null,
        ]);

        $raw = trim((string) $session->raw_content);

        if ($raw === '') {
            $session->update([
                'status' => IntakeStatus::Failed,
                'error_message' => 'Nothing to structure — raw content is empty.',
            ]);

            return;
        }

        $draft = $ai->isConfigured()
            ? $this->structureWithAi($ai, $raw)
            : $this->structureFallback($raw);

        $session->update([
            'structured_draft' => $draft,
            'status' => IntakeStatus::Structured,
            'error_message' => null,
        ]);
    }

    /** @return array{functional: list<string>, nonFunctional: list<string>, businessRules: list<string>} */
    private function structureWithAi(AiCompletionService $ai, string $raw): array
    {
        $system = <<<'PROMPT'
You are a Lead System Analyst. Organize fragmented stakeholder notes into three categories.
Return ONLY valid JSON with this shape:
{"functional":["..."],"nonFunctional":["..."],"businessRules":["..."]}
Each array item must be a concise requirement statement grounded in the notes.
Do not invent features unrelated to the notes.
PROMPT;

        $content = $ai->complete($system, $raw, ['temperature' => 0.1]);
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $content) ?? $content;

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return $this->structureFallback($raw);
        }

        return [
            'functional' => array_values(array_filter(array_map('strval', $decoded['functional'] ?? []))),
            'nonFunctional' => array_values(array_filter(array_map('strval', $decoded['nonFunctional'] ?? []))),
            'businessRules' => array_values(array_filter(array_map('strval', $decoded['businessRules'] ?? []))),
        ];
    }

    /** @return array{functional: list<string>, nonFunctional: list<string>, businessRules: list<string>} */
    private function structureFallback(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $functional = [];
        $nonFunctional = [];
        $businessRules = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/^[\*\-\d\.]+\s*/', '', $line) ?? $line);
            if (strlen($line) < 20) {
                continue;
            }

            if (preg_match('/\b(must|shall|should|need|feature|user can|system)\b/i', $line)
                && ! preg_match('/\b(performance|latency|security|availability|scalability|uptime)\b/i', $line)) {
                $functional[] = $line;
            } elseif (preg_match('/\b(performance|latency|security|availability|scalability|uptime|fast|secure)\b/i', $line)) {
                $nonFunctional[] = $line;
            } elseif (preg_match('/\b(rule|policy|only if|unless|cannot|must not|business)\b/i', $line)) {
                $businessRules[] = $line;
            } else {
                $functional[] = $line;
            }
        }

        return [
            'functional' => array_slice(array_values(array_unique($functional)), 0, 20),
            'nonFunctional' => array_slice(array_values(array_unique($nonFunctional)), 0, 15),
            'businessRules' => array_slice(array_values(array_unique($businessRules)), 0, 15),
        ];
    }

    public function failed(?Throwable $exception): void
    {
        $session = IntakeSession::query()->find($this->intakeSessionId);

        if (! $session) {
            return;
        }

        Log::error('Structure intake job failed', [
            'intake_session_id' => $this->intakeSessionId,
            'message' => $exception?->getMessage(),
        ]);

        $session->update([
            'status' => IntakeStatus::Failed,
            'error_message' => $exception?->getMessage() ?? 'Structuring failed.',
        ]);
    }
}
