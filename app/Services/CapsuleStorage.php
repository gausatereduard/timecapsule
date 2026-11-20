<?php

namespace App\Services;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * The only place in the app that touches uploaded files.
 *
 * Attachments are stored in a private S3 bucket under uploads/.
 */
class CapsuleStorage
{
    /** Allowed file types: real MIME type (sniffed from the file content) => extension we save with. */
    public const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** Saves the uploaded file under a random name and returns that name. */
    public function save(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        $name = bin2hex(random_bytes(16)).'.'.self::EXTENSIONS[$mime];

        // The bucket has ACLs disabled; upload without an ACL header.
        $this->disk()->getClient()->putObject([
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $this->key($name),
            'SourceFile' => $file->getPathname(),
            'ContentType' => $mime,
        ]);

        return $name;
    }

    public function delete(string $name): void
    {
        $this->disk()->delete($this->key($name));
    }

    /**
     * Redirects to S3 after the controller has checked the capsule's access rules.
     * Images are shown inline; PDFs and "?download=1" are downloaded as attachments.
     */
    public function stream(string $name, string $downloadName, string $mime, bool $download): RedirectResponse
    {
        $inline = ! $download && str_starts_with($mime, 'image/');
        $filename = trim(preg_replace('/[\x00-\x1f\x7f]/', '', str_replace(['/', '\\'], '_', $downloadName)));
        $filename = $filename !== '' ? $filename : $name;
        $fallback = trim(str_replace('%', '', Str::ascii($filename)));

        $url = $this->disk()->temporaryUrl($this->key($name), now()->addMinutes(5), [
            'ResponseContentType' => $mime,
            'ResponseContentDisposition' => HeaderUtils::makeDisposition(
                $inline ? 'inline' : 'attachment',
                $filename,
                $fallback !== '' ? $fallback : $name,
            ),
        ]);

        return redirect()->away($url)->header('Cache-Control', 'private, no-store');
    }

    private function key(string $name): string
    {
        return 'uploads/'.$name;
    }

    private function disk(): AwsS3V3Adapter
    {
        return Storage::disk('s3');
    }
}
