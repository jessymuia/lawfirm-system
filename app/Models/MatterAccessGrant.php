<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SEC-10: explicit access to one matter. Audited by DefaultAppModel; revoking soft-deletes (and is audited). */
class MatterAccessGrant extends DefaultAppModel
{
    protected $fillable = ['case_id', 'user_id', 'reason', 'expires_at', 'updated_by', 'deleted_by'];

    protected $casts = [
        'created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => 'datetime', 'expires_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
