<?php

namespace App\Modules\Projects\Jobs;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Projects\Models\ContextFile;
use App\Modules\Projects\Services\TextExtractionService;
use App\Support\UploadStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractContextTextJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public string $contextFileId,
    ) {}

    public function handle(TextExtractionService $extractor): void
    {
        $file = ContextFile::query()->find($this->contextFileId);

        if (! $file) {
            return;
        }

        $file->update([
            'status' => DocumentStatus::Processing,
            'error_message' => null,
        ]);

        $text = UploadStorage::withLocalPath(
            $file->storage_path,
            fn (string $absolute): string => $extractor->extract($absolute, $file->filename, $file->mime_type),
        );

        $file->update([
            'extracted_text' => $text,
            'status' => DocumentStatus::Completed,
            'error_message' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $file = ContextFile::query()->find($this->contextFileId);

        if (! $file) {
            return;
        }

        Log::error('Context text extraction failed', [
            'context_file_id' => $this->contextFileId,
            'message' => $exception?->getMessage(),
        ]);

        $file->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $exception?->getMessage() ?? 'Text extraction failed.',
        ]);
    }
}
