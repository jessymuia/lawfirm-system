<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use App\Jobs\SyncCalendarConnection;
use App\Matters\MatterScoped;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Hearing extends DefaultAppModel
{
    use HasPublicId;
    use MatterScoped;

    /** FUN-16: the people whose calendars show this hearing are synced after it changes. */
    protected static function booted(): void
    {
        parent::booted();
        $sync = function (Hearing $h) {
            $case = DB::table('cases')->where('id', $h->case_id)->value('assigned_to');
            $attorneys = DB::table('attorneys')->where('case_id', $h->case_id)->whereNull('deleted_at')->pluck('advocate_id')->all();
            SyncCalendarConnection::forUsers(array_merge([$case], $attorneys));
        };
        static::saved($sync);
        static::deleted($sync);
    }

    protected $fillable = [
        'start_time', 'duration_minutes', 'courtroom', 'judicial_officer', 'efiling_reference',   // FUN-16
        'case_id',
        'court_id',
        'hearing_date',
        'hearing_type',
        'notes',
        'outcome',
        'reminder_sent_at',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    /** @return BelongsTo<HearingType, $this> */
    public function hearingTypeName(): BelongsTo
    {
        return $this->belongsTo(HearingType::class, 'hearing_type');
    }

    /** @return BelongsTo<Cases, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(Cases::class, 'case_id')->withTrashed();
    }

    /** @return BelongsTo<Court, $this> */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class, 'court_id')->withTrashed();
    }
}
