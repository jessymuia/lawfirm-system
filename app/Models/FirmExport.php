<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ENG-21: one queued firm-wide export.
 *
 * @property int $id
 * @property int $requested_by
 * @property string $status
 * @property string|null $path
 * @property int|null $size
 * @property string|null $error
 * @property Carbon|null $ready_at
 * @property Carbon|null $expires_at
 */
class FirmExport extends Model
{
    protected $fillable = ['requested_by', 'status', 'path', 'size', 'error', 'ready_at', 'expires_at'];

    protected $casts = ['ready_at' => 'datetime', 'expires_at' => 'datetime', 'size' => 'integer'];

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'ready' && $this->expires_at?->isFuture() && $this->path !== null;
    }
}
