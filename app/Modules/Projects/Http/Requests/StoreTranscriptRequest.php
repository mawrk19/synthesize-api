<?php

namespace App\Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTranscriptRequest extends FormRequest
{
    private const AUDIO_EXTENSIONS = ['mp3', 'wav', 'm4a', 'mp4', 'mpeg', 'mpga', 'webm', 'ogg', 'aac', 'flac'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'transcript' => ['nullable', 'string', 'max:200000'],
            'audio' => ['nullable', 'file', 'max:4096'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $transcript = trim((string) $this->input('transcript', ''));

            if ($transcript === '' && ! $this->hasFile('audio')) {
                $validator->errors()->add('transcript', 'Provide a transcript or upload an audio file.');
            }

            if (! $this->hasFile('audio')) {
                return;
            }

            $ext = strtolower($this->file('audio')?->getClientOriginalExtension() ?? '');

            if (! in_array($ext, self::AUDIO_EXTENSIONS, true)) {
                $validator->errors()->add(
                    'audio',
                    'Allowed audio types: '.implode(', ', self::AUDIO_EXTENSIONS),
                );
            }
        });
    }
}
