<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UploadStorage
{
    public static function diskName(): string
    {
        return (string) config('filesystems.uploads_disk', 'local');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, self::diskName());
    }

    public static function delete(string $path): bool
    {
        return self::disk()->delete($path);
    }

    /**
     * @template TReturn
     *
     * @param  callable(string $absolutePath): TReturn  $callback
     * @return TReturn
     */
    public static function withLocalPath(string $path, callable $callback): mixed
    {
        if (self::usesLocalDriver()) {
            return $callback(self::disk()->path($path));
        }

        $localPath = self::materializeToTemp($path);

        try {
            return $callback($localPath);
        } finally {
            @unlink($localPath);
        }
    }

    private static function usesLocalDriver(): bool
    {
        return config('filesystems.disks.'.self::diskName().'.driver') === 'local';
    }

    private static function materializeToTemp(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $temp = tempnam(sys_get_temp_dir(), 'upload_');

        if ($temp === false) {
            throw new \RuntimeException('Could not create a temporary file for upload processing.');
        }

        if ($extension !== '') {
            $renamed = $temp.'.'.$extension;
            rename($temp, $renamed);
            $temp = $renamed;
        }

        $contents = self::disk()->get($path);

        if (! is_string($contents)) {
            @unlink($temp);

            throw new \RuntimeException("Could not read uploaded file at {$path}.");
        }

        file_put_contents($temp, $contents);

        return $temp;
    }
}
