<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Jobs\SyncCalendarConnection;
use App\Models\Concerns\ConstrainedToEnums;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends DefaultAppModel
{
    use ConstrainedToEnums;

    /** DATA-12: columns that only ever hold one of their enum's values */
    public const ENUM_COLUMNS = ['priority' => TaskPriority::class, 'task_status' => TaskStatus::class];

    /** FUN-16: the assignee's calendar (and a previous assignee's) is synced after a task changes. */
    protected static function booted(): void
    {
        parent::booted();
        $sync = fn (Task $t) => SyncCalendarConnection::forUsers([$t->assigned_to, $t->getOriginal('assigned_to')]);
        static::saved($sync);
        static::deleted($sync);
    }

    protected $fillable = [
        'description',
        'title',
        'assigned_to',
        'due_date',
        'priority',
        'task_status',
        'updated_by',
        'deleted_by',
    ];

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }
}
