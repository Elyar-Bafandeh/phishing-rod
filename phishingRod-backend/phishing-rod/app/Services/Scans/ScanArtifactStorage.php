<?php

namespace App\Services\Scans;

use App\Models\Scan;
use App\Models\ScanArtifact;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Persists urlscan.io artifacts (result JSON, rendered DOM, …) to disk and
 * tracks each one in the scan_artifacts table.
 *
 * Why a dedicated helper:
 *  - Large, untrusted blobs (DOM HTML, full provider JSON) must never live in
 *    the scans table — they are written under storage/app/private/scans/{uuid}/
 *    on the private "local" disk, which sits outside the public web root.
 *  - The DB only keeps lightweight metadata (path, sha256, size, content type)
 *    so downstream jobs can locate, verify, and read artifacts back cheaply.
 *  - Writes are idempotent per (scan, type): a retried job overwrites the file
 *    and updates the existing row instead of piling up duplicates.
 */
class ScanArtifactStorage
{
    /**
     * Disk used for all scan artifacts. The private local disk keeps content
     * out of the public web root; faked transparently in tests.
     */
    private const DISK = 'local';

    /**
     * Friendly file basenames for known artifact types. Anything not listed
     * falls back to the raw type string so unknown types still store cleanly.
     *
     * @var array<string, string>
     */
    private const BASENAMES = [
        'result_json' => 'result',
        'dom_html'    => 'dom',
    ];

    /**
     * Store a structured array as a pretty-printed JSON artifact.
     *
     * @param  array<mixed>  $data
     */
    public function storeJson(Scan $scan, string $type, array $data): ScanArtifact
    {
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($content === false) {
            throw new RuntimeException("Failed to encode artifact [{$type}] for scan {$scan->uuid} as JSON.");
        }

        return $this->storeText($scan, $type, $content, 'json', 'application/json');
    }

    /**
     * Store raw text content (e.g. DOM HTML) as an artifact and record it.
     */
    public function storeText(
        Scan $scan,
        string $type,
        string $content,
        string $extension,
        string $contentType,
    ): ScanArtifact {
        $path = $this->pathFor($scan, $type, $extension);

        $this->disk()->put($path, $content);

        return $scan->artifacts()->updateOrCreate(
            ['type' => $type],
            [
                'storage_path' => $path,
                'sha256'       => hash('sha256', $content),
                'size_bytes'   => strlen($content),
                'content_type' => $contentType,
            ],
        );
    }

    /**
     * Read an artifact's content back from disk.
     *
     * @throws RuntimeException When the artifact has no path or the file is gone.
     */
    public function readArtifact(ScanArtifact $artifact): string
    {
        $path = $artifact->storage_path;

        if (blank($path) || ! $this->disk()->exists($path)) {
            throw new RuntimeException("Artifact #{$artifact->id} content is missing on disk.");
        }

        return (string) $this->disk()->get($path);
    }

    /**
     * Build the storage path for an artifact, scoped to the scan's UUID so
     * each scan's files are isolated and easy to purge together.
     */
    private function pathFor(Scan $scan, string $type, string $extension): string
    {
        $basename = self::BASENAMES[$type] ?? $type;

        return "scans/{$scan->uuid}/{$basename}.{$extension}";
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
