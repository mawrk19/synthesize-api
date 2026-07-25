<?php

namespace App\Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTranscriptRequest extends FormRequest
{
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
            'audio' => ['nullable', 'file', 'max:51200', 'mimes:mp3,wav,m4a,mpeg,mpga,webm,ogg'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! filled($this->input('transcript')) && ! $this->hasFile('audio')) {
                $validator->errors()->add('transcript', 'Provide a transcript or upload an audio file.');
            }
        });
    }
}
