<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

/**
 * PLAT-8: one request for platform-support access to this firm. Audited like every other model (owen-it `audits`, tag
 * `support-access`), so creation, approval, start and end all appear in the firm's own audit log.
 */
class SupportAccessSession extends DefaultAppModel
{
    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => 'datetime',
        'requested_at' => 'datetime', 'approved_at' => 'datetime', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'ended_at' => 'datetime',
    ];

    public function generateTags(): array
    {
        return ['support-access'];
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at && $this->expires_at->isFuture();
    }
}
