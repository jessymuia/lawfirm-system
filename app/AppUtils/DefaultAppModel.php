<?php

namespace App\AppUtils;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class DefaultAppModel extends Model implements AuditableContract
{
    use Auditable, HasFactory, SoftDeletes;

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * SEC-7: created_by/status used to be listed in every model's
     * $fillable, which meant they could be set by whatever the client
     * sent in a create/update payload rather than by the server. Every
     * table created via Utils::createDefaultTableColumns() has a
     * created_by column, so this is safe to apply globally.
     *
     * created_by is no longer fillable at all (see child models) — it is
     * always stamped here from the authenticated user, once, at creation,
     * and can't be overridden by mass assignment or by a later update().
     * `status` is likewise no longer fillable; it keeps its DB column
     * default (see Utils::createDefaultTableColumns) and is only ever
     * changed by direct property assignment from trusted server-side code
     * (e.g. an explicit enable/disable action), never from request input.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->created_by) && Auth::check()) {
                $model->created_by = Auth::id();
            }
        });
    }

    // created by
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // updated by
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // deleted by
    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
