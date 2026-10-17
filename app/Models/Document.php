<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use App\Jobs\ExtractDocumentText;
use App\Matters\MatterScoped;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends DefaultAppModel
{
    use HasPublicId;
    use MatterScoped;

    protected $fillable = [
        'case_id',
        'document_group_id',
        'version',
        'is_current',
        'title',
        'filename',
        'filepath',
        'full_path',
        'disk',
        'mimetype',
        'filesize',
        'checksum',
        'extension',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'is_current' => 'boolean',
    ];

    /** FUN-8: every new file (or version) has its text read for search, on the queue, once the upload is committed. */
    protected static function booted(): void
    {
        parent::booted();   // keeps the created_by stamp
        static::created(function (Document $doc) {
            if (config('documents.text.enabled')) {
                ExtractDocumentText::dispatch((int) $doc->id)->afterCommit();
            }
        });
    }

    /** @return BelongsTo<Cases, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(Cases::class, 'case_id')->withTrashed();
    }

    /**
     * All versions of this document, oldest first.
     */
    /** @return HasMany<Document, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(Document::class, 'document_group_id', 'document_group_id')
            ->orderBy('version');
    }
}
