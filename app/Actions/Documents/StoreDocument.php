<?php

namespace App\Actions\Documents;

use App\Documents\MalwareScanner;
use App\Models\Document;
use App\Models\User;
use App\Tenancy\FirmStorage;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ENG-9: file a document on a matter: a staff upload, a client's portal upload (FUN-10), an email attachment (FUN-15),
 * a document made from a template or a signature certificate (FUN-14). One way for all of them:
 *  - the file lands on the firm's own disk under its prefix (TEN-2, TEN-12), with its SHA-256;
 *  - given the group of an existing document, it becomes that document's next version (FUN-5);
 *  - its text is read for search afterwards (FUN-8, a model event).
 */
class StoreDocument
{
    /**
     * @param  UploadedFile|string  $file  an upload, or the file's bytes
     * @param  array{title: string, filename?: string, mimetype?: string, document_group_id?: ?int}  $meta
     */
    public function handle(int $caseId, UploadedFile|string $file, array $meta, ?User $actor): Document
    {
        $upload = $file instanceof UploadedFile;
        $filename = $meta['filename'] ?? ($upload ? $file->getClientOriginalName() : 'document');
        // SEC-14: an infected file is refused before anything is stored
        $scanner = app(MalwareScanner::class);
        if ($scanner->enabled()) {
            $stream = $upload ? fopen($file->getRealPath(), 'rb') : null;
            try {
                $scanner->assertClean($stream ?: $file, $filename);
            } finally {
                is_resource($stream) && fclose($stream);
            }
        }
        $bytes = $upload ? null : $file;
        $disk = FirmStorage::disk();
        $dir = app(TenantContext::class)->storagePrefix('cases/docs');
        $name = uniqid().'_'.$filename;
        $path = $upload ? (string) $file->storeAs($dir, $name, $disk) : $dir.'/'.$name;
        if (! $upload) {
            Storage::disk($disk)->put($path, $bytes);
        }
        $checksum = $upload ? hash_file('sha256', $file->getRealPath()) : hash('sha256', $bytes);
        $size = $upload ? (int) $file->getSize() : strlen($bytes);
        $mime = $meta['mimetype'] ?? ($upload ? (string) $file->getClientMimeType() : ((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream'));

        return $this->record($caseId, $disk, $path, ['title' => $meta['title'], 'filename' => $filename, 'mimetype' => $mime, 'size' => $size,
            'checksum' => $checksum, 'document_group_id' => $meta['document_group_id'] ?? null], $actor);
    }

    /**
     * ENG-22: file a document whose bytes are already on the firm's disk (a direct upload, checked by its caller).
     *
     * @param  array{title: string, filename: string, mimetype: string, size: int, checksum: string, document_group_id?: ?int}  $meta
     */
    public function adopt(int $caseId, string $disk, string $path, array $meta, ?User $actor): Document
    {
        return $this->record($caseId, $disk, $path, $meta, $actor);
    }

    /** @param array{title: string, filename: string, mimetype: string, size: int, checksum: string, document_group_id?: ?int} $meta */
    private function record(int $caseId, string $disk, string $path, array $meta, ?User $actor): Document
    {
        return DB::transaction(function () use ($caseId, $meta, $actor, $path, $disk) {
            $version = 1;
            $group = null;
            if (! empty($meta['document_group_id'])) {
                $previous = Document::where('document_group_id', $meta['document_group_id'])->where('is_current', true)->lockForUpdate()->first();
                if ($previous) {
                    $version = $previous->version + 1;
                    $group = $previous->document_group_id;
                    $previous->forceFill(['is_current' => false] + ($actor ? ['updated_by' => $actor->getKey()] : []))->save();
                }
            }
            $doc = new Document;
            $doc->fill([
                'title' => $meta['title'], 'case_id' => $caseId, 'version' => $version, 'is_current' => true, 'filename' => $meta['filename'],
                'filepath' => $path, 'full_path' => $path, 'disk' => $disk, 'mimetype' => $meta['mimetype'], 'filesize' => $meta['size'], 'checksum' => $meta['checksum'],
                'extension' => strtolower(pathinfo($meta['filename'], PATHINFO_EXTENSION)),
            ]);
            $actor && $doc->forceFill(['created_by' => $actor->getKey()]);
            $doc->save();
            // a new document is its own group of one, keyed by its own id (FUN-5)
            $doc->forceFill(['document_group_id' => $group ?? $doc->id])->save();

            return $doc;
        });
    }
}
