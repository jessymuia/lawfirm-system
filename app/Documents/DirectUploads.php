<?php

namespace App\Documents;

use App\Actions\ActionException;
use App\Actions\Documents\StoreDocument;
use App\Http\Requests\Document\StoreRequest as DocumentRules;
use App\Models\Document;
use App\Models\User;
use App\Tenancy\FirmStorage;
use App\Tenancy\TenantContext;
use Aws\S3\PostObjectV4;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ENG-22: the browser sends a document straight to the firm's bucket, not through PHP, so a large file neither ties up a
 * web worker nor meets PHP's upload limits.
 *
 *  1. begin(): the app checks the request (matter, type, size), reserves an object key under the firm's prefix (TEN-12)
 *     and answers a signed S3 POST policy valid for a few minutes that allows only that key, type and exact length;
 *  2. the browser POSTs the file to the bucket with those fields;
 *  3. complete(): the app checks what arrived (it exists, it is the size announced, its content is an accepted type),
 *     hashes it and files it as a document (StoreDocument, ENG-9): from here on it is like any other upload.
 *
 * Unfinished uploads expire; `documents:prune-uploads` deletes what they left in the bucket. Only an S3-compatible
 * disk can do this; elsewhere begin() says so and the web app uploads through the API as before.
 */
class DirectUploads
{
    public const MINUTES = 15;

    public function __construct(private StoreDocument $store) {}

    public function available(): bool
    {
        return (bool) config('documents.direct_uploads.enabled', true) && config('filesystems.disks.'.FirmStorage::disk().'.driver') === 's3';
    }

    public static function maxBytes(): int
    {
        return (int) config('documents.direct_uploads.max_mb', 500) * 1024 * 1024;
    }

    /** @return array{id: string, url: string, fields: array<string, string>, expires_at: string} */
    public function begin(User $by, int $caseId, string $title, string $filename, string $mimetype, int $size, ?int $groupId = null): array
    {
        if (! $this->available()) {
            throw new ActionException('Direct uploads are not available here; upload through the API.', 409, 'direct_uploads_unavailable');
        }
        $disk = FirmStorage::disk();
        $filename = $this->safeName($filename);
        $path = app(TenantContext::class)->storagePrefix('cases/docs').'/'.uniqid().'_'.$filename;
        $expires = now()->addMinutes(self::MINUTES);
        $token = (string) Str::uuid();
        DB::table('document_uploads')->insert([
            'token' => $token, 'case_id' => $caseId, 'user_id' => $by->getKey(), 'title' => $title, 'filename' => $filename,
            'mimetype' => $mimetype, 'size' => $size, 'document_group_id' => $groupId, 'disk' => $disk, 'path' => $path,
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A POST policy, not a pre-signed PUT: S3 itself refuses a different key, type or a larger file.
        /** @var \Illuminate\Filesystem\AwsS3V3Adapter $s3 */
        $s3 = Storage::build((array) config("filesystems.disks.{$disk}"));   // the disk as configured, never a test double
        $key = $s3->path($path);
        $bucket = (string) config("filesystems.disks.{$disk}.bucket");
        $fields = ['key' => $key, 'Content-Type' => $mimetype];
        $conditions = [['bucket' => $bucket], ['eq', '$key', $key], ['eq', '$Content-Type', $mimetype], ['content-length-range', $size, $size]];
        // TEN-13: a firm with its own KMS key: the browser's upload is encrypted with it too, and S3 refuses one that is not
        $options = (array) config("filesystems.disks.{$disk}.options", []);
        if (($options['ServerSideEncryption'] ?? null) === 'aws:kms') {
            $fields += ['x-amz-server-side-encryption' => 'aws:kms', 'x-amz-server-side-encryption-aws-kms-key-id' => (string) $options['SSEKMSKeyId']];
            $conditions[] = ['eq', '$x-amz-server-side-encryption', 'aws:kms'];
            $conditions[] = ['eq', '$x-amz-server-side-encryption-aws-kms-key-id', (string) $options['SSEKMSKeyId']];
        }
        $post = new PostObjectV4($s3->getClient(), $bucket, $fields, $conditions, '+'.self::MINUTES.' minutes');

        return [
            'id' => $token,
            'url' => (string) $post->getFormAttributes()['action'],
            'fields' => $post->getFormInputs(),   // send these, then the file last, as multipart/form-data
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    public function complete(string $token, User $by): Document
    {
        return DB::transaction(function () use ($token, $by) {
            $u = DB::table('document_uploads')->where('token', $token)->where('user_id', $by->getKey())->lockForUpdate()->first();
            if (! $u || $u->completed_at) {
                throw new ActionException('This upload is not open (finished already, or not yours).', 404);
            }
            if (now()->greaterThan($u->expires_at)) {
                throw new ActionException('This upload has expired. Start it again.', 410, 'upload_expired');
            }
            $disk = Storage::disk($u->disk);
            if (! $disk->exists($u->path)) {
                throw new ActionException('The file has not arrived yet.', 409, 'upload_missing');
            }
            $size = (int) $disk->size($u->path);
            $mime = $this->sniff($u->disk, $u->path);
            if ($size !== (int) $u->size || $size > self::maxBytes() || ! in_array($mime, DocumentRules::ALLOWED_MIME_TYPES, true)) {
                $disk->delete($u->path);
                DB::table('document_uploads')->where('id', $u->id)->delete();
                throw new ActionException('The file that arrived is not the one announced, or is not an accepted type.', 422, 'upload_rejected');
            }

            // SEC-14: scanned where it landed; an infected object is deleted and the upload refused
            $stream = $disk->readStream($u->path);
            try {
                app(MalwareScanner::class)->assertClean($stream, (string) $u->filename);
            } catch (ActionException $e) {
                if ($e->status === 422) {
                    $disk->delete($u->path);
                    DB::table('document_uploads')->where('id', $u->id)->delete();
                }
                throw $e;
            } finally {
                is_resource($stream) && fclose($stream);
            }

            $doc = $this->store->adopt((int) $u->case_id, $u->disk, $u->path, ['title' => $u->title, 'filename' => $u->filename,
                'mimetype' => $mime, 'size' => $size, 'checksum' => $this->sha256($u->disk, $u->path),
                'document_group_id' => $u->document_group_id], $by);
            DB::table('document_uploads')->where('id', $u->id)->update(['completed_at' => now(), 'document_id' => $doc->id, 'updated_at' => now()]);

            return $doc;
        });
    }

    /** Uploads begun and never finished: their objects are deleted and the reservations forgotten. */
    public function prune(): int
    {
        $n = 0;
        DB::table('document_uploads')->whereNull('completed_at')->where('expires_at', '<', now()->subMinutes(5))->orderBy('id')
            ->each(function ($u) use (&$n) {
                Storage::disk($u->disk)->delete($u->path);
                DB::table('document_uploads')->where('id', $u->id)->delete();
                $n++;
            });
        DB::table('document_uploads')->whereNotNull('completed_at')->where('completed_at', '<', now()->subDays(30))->delete();

        return $n;
    }

    private function safeName(string $name): string
    {
        $name = trim(str_replace(['/', '\\', "\0"], '_', $name));

        return mb_substr($name === '' ? 'document' : $name, -180);
    }

    /** What the content is, from its first bytes (never what the browser said). */
    private function sniff(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        $head = is_resource($stream) ? (string) fread($stream, 65536) : '';
        is_resource($stream) && fclose($stream);

        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($head) ?: 'application/octet-stream';
    }

    private function sha256(string $disk, string $path): string
    {
        $ctx = hash_init('sha256');
        $stream = Storage::disk($disk)->readStream($path);
        hash_update_stream($ctx, $stream);
        is_resource($stream) && fclose($stream);

        return hash_final($ctx);
    }
}
