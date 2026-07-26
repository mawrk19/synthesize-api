<?php

namespace App\Modules\Projects\Jobs;

use App\Modules\Core\Services\AiCompletionService;
use App\Modules\Projects\Enums\IntakeStatus;
use App\Modules\Projects\Models\IntakeSession;
use App\Support\UploadStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class TranscribeAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public string $intakeSessionId,
    ) {}

    public function handle(AiCompletionService $ai): void
    {
        $session = IntakeSession::query()->find($this->intakeSessionId);

        if (! $session || ! $session->audio_path) {
            return;
        }

        $session->update([
            'status' => IntakeStatus::Processing,
            'error_message' => null,
        ]);

        if (! $ai->isTranscriptionConfigured()) {
            $session->update([
                'status' => IntakeStatus::Failed,
                'error_message' => 'AI_API_KEY is required for audio transcription (Groq Whisper). Paste a transcript instead.',
            ]);

            return;
        }

        $filename = basename($session->audio_path);
        $text = UploadStorage::withLocalPath(
            $session->audio_path,
            fn (string $absolute): string => $ai->transcribe($absolute, $filename),
        );

        $session->update([
            'raw_content' => $text,
            'status' => IntakeStatus::Draft,
            'error_message' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $session = IntakeSession::query()->find($this->intakeSessionId);

        if (! $session) {
            return;
        }

        Log::error('Audio transcription failed', [
            'intake_session_id' => $this->intakeSessionId,
            'message' => $exception?->getMessage(),
        ]);

        $session->update([
            'status' => IntakeStatus::Failed,
            'error_message' => $exception?->getMessage() ?? 'Transcription failed.',
        ]);
    }
}
